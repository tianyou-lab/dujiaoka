<?php

namespace App\PaymentGateways\Drivers;

use App\PaymentGateways\AbstractPaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Yansongda\Pay\Pay;
use App\Models\Order;
use App\Models\Pay as PayModel;

/**
 * 支付宝支付驱动（适配 yansongda/pay v3 证书模式）
 */
class AlipayDriver extends AbstractPaymentDriver
{
    /**
     * 支付网关处理
     */
    public function gateway(string $payway, string $orderSN, Order $order, PayModel $payGateway)
    {
        try {
            $this->order = $order;
            $this->payGateway = $payGateway;
            $this->validateOrderStatus();

            $config = $this->buildConfig();
            $orderData = $this->getOrderInfo();

            return $this->processPayway($payway, $config, $orderData);

        } catch (\Throwable $e) {
            $detail = sprintf(
                '[%s] %s @ %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            try {
                Log::error('AlipayDriver gateway error', [
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $ignored) {
            }

            $showDetail = config('app.debug') === true || config('app.env') !== 'production';
            $message = $showDetail
                ? __('dujiaoka.prompt.abnormal_payment_channel') . ' ' . $detail
                : __('dujiaoka.prompt.abnormal_payment_channel');

            return $this->err($message);
        }
    }

    /**
     * 异步通知处理
     */
    public function notify(Request $request): string
    {
        try {
            $orderSN = $request->input('out_trade_no');
            $orderService = app(\App\Services\Orders::class);
            $order = $orderService->detailOrderSN($orderSN);

            if (!$order) {
                return 'error';
            }

            $payService = app(\App\Services\Payment::class);
            $payGateway = $payService->detail($order->pay_id);

            if (!$payGateway || $payGateway->pay_handleroute !== 'alipay') {
                return 'error';
            }

            $this->payGateway = $payGateway;
            $this->order = $order;
            $config = $this->buildConfig();

            $result = $this->verify($config, $request);

            if ($result['status'] !== 'success') {
                return 'fail';
            }

            $this->processPaymentSuccess(
                $result['out_trade_no'],
                $result['total_amount'],
                $result['trade_no']
            );

            return 'success';
        } catch (\Exception $e) {
            Log::error('Alipay notify exception: ' . $e->getMessage());
            return 'fail';
        }
    }

    /**
     * 验证支付结果（v3 callback）
     */
    public function verify(array $config, Request $request): array
    {
        Pay::config($config);
        $data = Pay::alipay()->callback();

        $tradeStatus = $data->get('trade_status');
        if ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED') {
            return [
                'status' => 'success',
                'out_trade_no' => $data->get('out_trade_no'),
                'total_amount' => $data->get('total_amount'),
                'trade_no' => $data->get('trade_no'),
            ];
        }

        return ['status' => 'failed'];
    }

    /**
     * 获取支持的支付方式
     */
    public function getSupportedPayways(): array
    {
        return ['zfbf2f', 'alipayscan', 'aliweb', 'aliwap'];
    }

    public function getName(): string
    {
        return 'alipay';
    }

    public function getDisplayName(): string
    {
        return '支付宝';
    }

    /**
     * 构建支付配置（yansongda/pay v3 证书模式）
     *
     * 数据库存的是证书内容，运行时落盘成 .crt 文件，再把路径塞给 SDK。
     */
    protected function buildConfig(): array
    {
        $payGateway = $this->payGateway;

        $appId = trim((string)$payGateway->merchant_id);
        if ($appId === '') {
            throw new \RuntimeException('支付宝 app_id 未配置：请到后台 → 支付通道 → 编辑当前支付宝通道，把「商户号/APPID」填上');
        }

        $appSecretCert = $this->normalizePrivateKey((string)$payGateway->merchant_pem);
        if ($appSecretCert === '') {
            throw new \RuntimeException('支付宝应用私钥未配置：请到后台 → 支付通道 → 编辑当前支付宝通道，把「应用私钥 merchant_pem」粘进去');
        }

        $appPublicCertPath = $this->materializeCert($payGateway, 'app_public_cert');
        $alipayPublicCertPath = $this->materializeCert($payGateway, 'alipay_public_cert');
        $alipayRootCertPath = $this->materializeCert($payGateway, 'alipay_root_cert');

        return [
            'alipay' => [
                'default' => [
                    'app_id' => $appId,
                    // 应用私钥：v3 接受字符串或路径，这里直接传字符串（已剥掉 PEM 头尾）
                    'app_secret_cert' => $appSecretCert,
                    'app_public_cert_path' => $appPublicCertPath,
                    'alipay_public_cert_path' => $alipayPublicCertPath,
                    'alipay_root_cert_path' => $alipayRootCertPath,
                    'return_url' => isset($this->order) ? $this->getReturnUrl($this->order->order_sn) : '',
                    'notify_url' => $this->getNotifyUrl(),
                    'mode' => Pay::MODE_NORMAL,
                ],
            ],
            'http' => [
                'timeout' => 10.0,
                'connect_timeout' => 10.0,
            ],
            'logger' => [
                'enable' => false,
            ],
        ];
    }

    /**
     * 规范化应用私钥：
     * - 用户可能粘贴完整 PEM（带 -----BEGIN PRIVATE KEY----- 头尾），也可能只粘贴 base64 纯文本
     * - yansongda/pay v3 的字符串模式期望纯 base64，因此统一剥掉 PEM 头尾 + 去空白
     * - 若已经是纯 base64 则直接保留
     */
    protected function normalizePrivateKey(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strpos($raw, '-----BEGIN') !== false) {
            $raw = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----/', '', $raw);
        }

        return preg_replace('/\s+/', '', (string)$raw);
    }

    /**
     * 把数据库里的证书内容写成临时文件并返回路径。
     * 用 md5(content) 做缓存键，避免每次请求重复落盘。
     *
     * 若用户只粘了 base64 内容没有 PEM 头尾，自动补齐 -----BEGIN/END CERTIFICATE-----
     */
    protected function materializeCert(PayModel $payGateway, string $field): string
    {
        $raw = (string)($payGateway->{$field} ?? '');
        $content = $this->normalizeCertContent($raw);

        $labelMap = [
            'app_public_cert' => '应用公钥证书（appCertPublicKey_*.crt）',
            'alipay_public_cert' => '支付宝公钥证书（alipayCertPublicKey_RSA2.crt）',
            'alipay_root_cert' => '支付宝根证书（alipayRootCert.crt）',
        ];
        $label = $labelMap[$field] ?? $field;

        if ($content === '') {
            throw new \RuntimeException(sprintf(
                '支付宝证书未配置：[%s]，请到后台 → 支付通道 → 编辑当前支付宝通道，把对应 .crt 文件内容粘贴进去',
                $label
            ));
        }

        if (strpos($content, '-----BEGIN CERTIFICATE-----') === false) {
            throw new \RuntimeException(sprintf(
                '支付宝证书格式错误：[%s]，粘贴的内容必须是完整的 .crt 文件（包含 -----BEGIN CERTIFICATE----- 头），而不是纯文本或别的格式',
                $label
            ));
        }

        $dir = storage_path('app/alipay_certs');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf(
                    '无法创建证书临时目录 %s，请检查 storage/app/ 目录的写权限（755/775 或让 php-fpm 用户可写）',
                    $dir
                ));
            }
        }

        if (!is_writable($dir)) {
            throw new \RuntimeException(sprintf(
                '证书临时目录 %s 不可写，请检查权限（chown -R www-data:www-data storage & chmod -R 775 storage）',
                $dir
            ));
        }

        $hash = substr(md5($content), 0, 12);
        $path = $dir . DIRECTORY_SEPARATOR . sprintf('pay_%d_%s_%s.crt', $payGateway->id, $field, $hash);

        if (!is_file($path)) {
            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException(sprintf(
                    '写入证书文件失败：%s（检查磁盘空间和目录权限）',
                    $path
                ));
            }
            @chmod($path, 0640);
        }

        return $path;
    }

    /**
     * 规范化证书内容：用户粘贴时常见 3 种姿势
     *   1) 完整带 -----BEGIN CERTIFICATE----- 头尾（标准）
     *   2) 只粘 base64 主体（漏掉头尾）
     *   3) Windows 记事本打开粘贴带 \r\n 或 BOM
     */
    protected function normalizeCertContent(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (strpos($raw, '-----BEGIN CERTIFICATE-----') !== false) {
            return $raw;
        }

        $body = preg_replace('/\s+/', '', $raw);
        if ($body === '' || $body === null) {
            return '';
        }

        $body = chunk_split($body, 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . $body . "-----END CERTIFICATE-----\n";
    }

    /**
     * 处理不同支付方式
     */
    protected function processPayway(string $payway, array $config, array $orderData)
    {
        switch ($payway) {
            case 'zfbf2f':
            case 'alipayscan':
                return $this->handleScanPay($config, $orderData);

            case 'aliweb':
                return $this->handleWebPay($config, $orderData);

            case 'aliwap':
                return $this->handleWapPay($config, $orderData);

            default:
                return $this->err(__('dujiaoka.prompt.payment_method_not_supported'));
        }
    }

    /**
     * 当面付（出示二维码给买家扫）
     */
    protected function handleScanPay(array $config, array $orderData)
    {
        Pay::config($config);
        $result = Pay::alipay()->scan($orderData);

        return $this->render('morpho::static_pages.qrpay', [
            'payname' => $this->order->order_sn,
            'actual_price' => (float)$this->order->actual_price,
            'orderid' => $this->order->order_sn,
            'qr_code' => $result->get('qr_code'),
        ], __('dujiaoka.scan_qrcode_to_pay'));
    }

    /**
     * 电脑网页支付
     */
    protected function handleWebPay(array $config, array $orderData)
    {
        Pay::config($config);
        return Pay::alipay()->web($orderData);
    }

    /**
     * 手机 H5 支付（v3 中 wap 已更名为 h5）
     */
    protected function handleWapPay(array $config, array $orderData)
    {
        Pay::config($config);
        return Pay::alipay()->h5($orderData);
    }
}

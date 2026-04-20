<?php

namespace App\PaymentGateways\Drivers;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay as PayModel;
use App\Models\VmqPayOrder;
use App\Models\VmqQrcode;
use App\Models\VmqSetting;
use App\Models\VmqTmpPrice;
use App\PaymentGateways\AbstractPaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 嵌入式 V免签 驱动（无需独立监控端）
 *
 * 流程：
 *   1. 用户下单 → 命中本驱动的 gateway() → 创建 vmq_pay_orders 记录、分配错位金额
 *   2. redirect 到本站内置收银台 /pay/vmq/cashier/{orderSN}，显示二维码 + 前端轮询
 *   3. 用户在线支付宝/微信扫码付款 → 安卓 V免签 App 监听到账 → POST /appPush 到本站
 *   4. VmqApiController@appPush 收到推送后匹配金额，完成 vmq_pay_orders.state=1
 *   5. 收银台前端轮询 /checkOrder 发现 state=1 → 调用 OrderProcess::completedOrder 履约
 *
 * pays 表字段复用：
 *   - pay_handleroute = 'vmq'
 *   - pay_check       = 'vwx' (微信) / 'vzfb' (支付宝)
 *   - merchant_id / merchant_key / merchant_pem 全部可留空（嵌入式方案不需要外部 URL）
 *
 * 通讯密钥与 App 心跳/推送签名密钥统一存储在 vmq_settings.key 里，
 * 管理员在 Filament 后台「V免签 设置」处配置，或通过 artisan vmq:install 初始化。
 */
class VmqDriver extends AbstractPaymentDriver
{
    public const PAYWAY_WECHAT = 'vwx';
    public const PAYWAY_ALIPAY = 'vzfb';

    public const TYPE_WECHAT = 1;
    public const TYPE_ALIPAY = 2;

    /**
     * 金额错位最大尝试次数
     */
    public const MAX_PRICE_TRY = 300;

    public function gateway(string $payway, string $orderSN, Order $order, PayModel $payGateway)
    {
        try {
            $this->order = $order;
            $this->payGateway = $payGateway;

            $this->validateOrderStatus();

            // V免签 全局开关
            if ((string) VmqSetting::get('enable', '1') !== '1') {
                throw new RuleValidationException('V免签 已全局停用，请到后台「V免签 → 全局设置」重新启用');
            }

            $type  = $this->resolveType($payway);
            $price = $this->formatPrice((float) $order->actual_price);
            $priceFen = (int) bcmul($price, '100', 0);

            if ($priceFen <= 0) {
                throw new RuleValidationException('订单金额必须大于 0');
            }

            // 幂等：已存在 vmq_pay_orders 记录（用户刷新二维码页面）则直接跳收银台
            $existing = VmqPayOrder::where('order_sn', $order->order_sn)->first();
            if ($existing && $existing->state === VmqPayOrder::STATE_WAIT) {
                return redirect()->to($this->getCashierUrl($order->order_sn));
            }

            $payQf    = (int) VmqSetting::get('pay_qf', '1');
            $vmqOrderId = $this->generateVmqOrderId();

            // 金额错位抢占：用 UNIQUE(price_key) 做分布式互斥
            $realFen = $priceFen;
            $locked  = false;
            for ($i = 0; $i < self::MAX_PRICE_TRY; $i++) {
                $priceKey = $realFen . '-' . $type;
                try {
                    $inserted = DB::insert(
                        'INSERT IGNORE INTO vmq_tmp_prices (price_key, vmq_order_id, create_date) VALUES (?, ?, ?)',
                        [$priceKey, $vmqOrderId, time()]
                    );
                    if ($inserted) {
                        $locked = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning('VmqDriver tmp_price insert exception', ['msg' => $e->getMessage()]);
                }
                $realFen = $payQf === 2 ? ($realFen - 1) : ($realFen + 1);
                if ($realFen <= 0) {
                    break;
                }
            }

            if (!$locked) {
                throw new RuleValidationException('当前并发下单过多，请稍后重试（金额错位池已满）');
            }

            $reallyPrice = number_format($realFen / 100, 2, '.', '');

            // 尝试命中固定金额收款码（可选）
            $fixed = VmqQrcode::where('type', $type)
                ->where('price', $reallyPrice)
                ->where('enable', 1)
                ->first();
            $payUrl = $fixed ? (string) $fixed->pay_url : '';
            $isAuto = $fixed ? 0 : 1;

            VmqPayOrder::create([
                'order_sn'     => $order->order_sn,
                'vmq_order_id' => $vmqOrderId,
                'pay_id'       => $payGateway->id,
                'type'         => $type,
                'price'        => $price,
                'really_price' => $reallyPrice,
                'pay_url'      => $payUrl,
                'is_auto'      => $isAuto,
                'state'        => VmqPayOrder::STATE_WAIT,
                'create_date'  => time(),
                'pay_date'     => 0,
                'close_date'   => 0,
                'param'        => $order->order_sn,
            ]);

            Log::info('VmqDriver createOrder', [
                'order_sn'     => $order->order_sn,
                'vmq_order_id' => $vmqOrderId,
                'type'         => $type,
                'price'        => $price,
                'really_price' => $reallyPrice,
                'is_auto'      => $isAuto,
            ]);

            return redirect()->to($this->getCashierUrl($order->order_sn));
        } catch (RuleValidationException $e) {
            return $this->err($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('VmqDriver gateway error', [
                'order_sn'  => $order->order_sn ?? null,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            $showDetail = config('app.debug') === true || config('app.env') !== 'production';
            $detail = $showDetail ? sprintf(' [%s] %s @ %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()) : '';

            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel') . $detail);
        }
    }

    /**
     * 本驱动不使用独立监控端回调，notify 仅保留以免路由 404。
     * 真正的到账推送由 App 直接请求 /appPush，在 VmqApiController 里处理。
     */
    public function notify(Request $request): string
    {
        return 'success';
    }

    public function verify(array $config, Request $request): array
    {
        return [];
    }

    public function getSupportedPayways(): array
    {
        return [self::PAYWAY_WECHAT, self::PAYWAY_ALIPAY];
    }

    public function getName(): string
    {
        return 'vmq';
    }

    public function getDisplayName(): string
    {
        return 'V免签（嵌入式）';
    }

    /**
     * 智能识别支付类型，兼容用户随意填的 pay_check。
     */
    protected function resolveType(string $payway): int
    {
        $needle = mb_strtolower(trim($payway));
        if (
            str_contains($needle, 'zfb') ||
            str_contains($needle, 'alipay') ||
            str_contains($needle, '支付宝')
        ) {
            return self::TYPE_ALIPAY;
        }
        return self::TYPE_WECHAT;
    }

    protected function generateVmqOrderId(): string
    {
        return date('YmdHis') . mt_rand(10000, 99999);
    }

    protected function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    protected function getCashierUrl(string $orderSN): string
    {
        return url('/pay/vmq/cashier/' . $orderSN);
    }
}

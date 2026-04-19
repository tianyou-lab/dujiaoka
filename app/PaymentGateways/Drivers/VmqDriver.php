<?php

namespace App\PaymentGateways\Drivers;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay as PayModel;
use App\PaymentGateways\AbstractPaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * V免签 / 豆包蛋（VMQ / VPay 协议）支付驱动
 *
 * 数据库字段映射（复用 pays 表既有字段）：
 *   - merchant_id  => 通讯密钥（key）          数据库里通常填 32 位随机串
 *   - merchant_pem => V免签监控端的 URL        例如 https://tk.doubaodan.com/
 *   - merchant_key 未使用
 *
 * pay_handleroute 请填：vmq
 *
 * 支持两种 payway：
 *   - vwx   微信扫码（type=1）
 *   - vzfb  支付宝扫码（type=2）
 *
 * 协议参考（社区惯例，V免签/V免签升级版/豆包蛋通用）：
 *   createOrder?
 *     payId=<商户订单号>
 *     &type=<1|2>
 *     &price=<金额>
 *     &sign=md5(payId + param + type + price + key)
 *     &param=<自定义参数，这里传订单号>
 *     &notifyUrl=<异步回调地址>
 *     &returnUrl=<同步返回地址>
 *     &isHtml=1   // 让监控端直接跳转到自带的收银页，兼容性最好
 *
 * 回调校验 sign = md5(payId + param + type + price + reallyPrice + key)
 * 校验通过后向监控端响应纯文本 "success"，否则响应 "fail"，监控端会自动补单。
 */
class VmqDriver extends AbstractPaymentDriver
{
    public const PAYWAY_WECHAT = 'vwx';
    public const PAYWAY_ALIPAY = 'vzfb';

    public const TYPE_WECHAT = 1;
    public const TYPE_ALIPAY = 2;

    public function gateway(string $payway, string $orderSN, Order $order, PayModel $payGateway)
    {
        try {
            $this->order = $order;
            $this->payGateway = $payGateway;

            if (!in_array($payway, $this->getSupportedPayways(), true)) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_method_not_supported'));
            }

            $this->validateOrderStatus();

            $endpoint = $this->resolveEndpoint((string) $payGateway->merchant_pem);
            $key = trim((string) $payGateway->merchant_id);

            if ($endpoint === '') {
                throw new RuleValidationException('V免签监控端地址未配置：请到后台 → 支付通道 → 编辑当前V免签通道，把监控端 URL 填到「商户密钥 merchant_pem」字段');
            }
            if ($key === '') {
                throw new RuleValidationException('V免签通讯密钥未配置：请到后台 → 支付通道 → 编辑当前V免签通道，把通讯密钥填到「商户号 merchant_id」字段');
            }

            $type = $payway === self::PAYWAY_ALIPAY ? self::TYPE_ALIPAY : self::TYPE_WECHAT;

            $payId = $this->generatePayId();
            $param = $order->order_sn;
            $price = $this->formatPrice((float) $order->actual_price);

            $parameter = [
                'payId'     => $payId,
                'type'      => $type,
                'price'     => $price,
                'sign'      => md5($payId . $param . $type . $price . $key),
                'param'     => $param,
                'notifyUrl' => $this->getNotifyUrl(),
                'returnUrl' => $this->getReturnUrlWithSN($order->order_sn),
                'isHtml'    => 1,
            ];

            $payUrl = rtrim($endpoint, '/') . '/createOrder?' . http_build_query($parameter);

            Log::info('VmqDriver createOrder', [
                'order_sn' => $order->order_sn,
                'endpoint' => $endpoint,
                'payId'    => $payId,
                'type'     => $type,
                'price'    => $price,
                'payway'   => $payway,
            ]);

            return redirect()->away($payUrl);
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

    public function notify(Request $request): string
    {
        $data = $request->all();

        try {
            Log::info('Vmq notify received', [
                'payload' => $data,
                'ip'      => $request->ip(),
            ]);

            $orderSN = (string) ($data['param'] ?? '');
            if ($orderSN === '') {
                Log::warning('Vmq notify: param(order_sn) missing');
                return 'fail';
            }

            $orderService = app(\App\Services\Orders::class);
            $order = $orderService->detailOrderSN($orderSN);
            if (!$order) {
                Log::warning('Vmq notify: order not found', ['order_sn' => $orderSN]);
                return 'fail';
            }

            $payService = app(\App\Services\Payment::class);
            $payGateway = $payService->detail($order->pay_id);
            if (!$payGateway || $payGateway->pay_handleroute !== 'vmq') {
                Log::warning('Vmq notify: pay gateway mismatch', [
                    'order_sn'        => $orderSN,
                    'pay_id'          => $order->pay_id,
                    'pay_handleroute' => $payGateway?->pay_handleroute,
                ]);
                return 'fail';
            }

            $key         = (string) $payGateway->merchant_id;
            $payId       = (string) ($data['payId']       ?? '');
            $param       = (string) ($data['param']       ?? '');
            $type        = (string) ($data['type']        ?? '');
            $price       = (string) ($data['price']       ?? '');
            $reallyPrice = (string) ($data['reallyPrice'] ?? '');
            $sign        = (string) ($data['sign']        ?? '');

            $expected = md5($payId . $param . $type . $price . $reallyPrice . $key);
            if (!hash_equals($expected, $sign)) {
                Log::warning('Vmq notify: sign mismatch', [
                    'order_sn' => $orderSN,
                    'expected' => $expected,
                    'got'      => $sign,
                ]);
                return 'fail';
            }

            $payAmount = $reallyPrice !== '' ? (float) $reallyPrice : (float) $price;
            $this->processPaymentSuccess($param, $payAmount, $payId !== '' ? $payId : $orderSN);

            Log::info('Vmq notify: order completed', [
                'order_sn'    => $orderSN,
                'payId'       => $payId,
                'price'       => $price,
                'reallyPrice' => $reallyPrice,
            ]);

            return 'success';
        } catch (\Throwable $e) {
            Log::error('Vmq notify exception', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'payload'   => $data,
            ]);
            return 'fail';
        }
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
        return 'V免签 / 豆包蛋';
    }

    protected function resolveEndpoint(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        return $raw;
    }

    protected function generatePayId(): string
    {
        return date('YmdHis') . random_int(1000, 9999);
    }

    protected function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    protected function getReturnUrlWithSN(string $orderSN): string
    {
        return url('/order/detail/' . $orderSN);
    }
}

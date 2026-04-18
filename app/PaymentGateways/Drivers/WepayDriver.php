<?php

namespace App\PaymentGateways\Drivers;

use App\PaymentGateways\AbstractPaymentDriver;
use App\Exceptions\RuleValidationException;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Pay;
use Yansongda\Pay\Pay as YansongdaPay;

/**
 * 微信支付驱动
 */
class WepayDriver extends AbstractPaymentDriver
{
    /**
     * 支付网关处理
     */
    public function gateway(string $payway, string $orderSN, Order $order, Pay $payGateway)
    {
        try {
            $this->order = $order;
            $this->payGateway = $payGateway;

            // 验证支付方式
            if (!in_array($payway, $this->getSupportedPayways())) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_method_not_supported'));
            }

            $this->validateOrderStatus();

            $config = [
                'app_id' => $this->payGateway->merchant_id,
                'mch_id' => $this->payGateway->merchant_key,
                'key' => $this->payGateway->merchant_pem,
                'notify_url' => url('/pay/wepay/notify'),
                'return_url' => url('/order/detail/' . $this->order->order_sn),
                'http' => [
                    'timeout' => 10.0,
                    'connect_timeout' => 10.0,
                ],
            ];

            $orderData = [
                'out_trade_no' => $this->order->order_sn,
                'total_fee' => bcmul($this->order->actual_price, 100, 0),
                'body' => $this->order->order_sn
            ];

            switch ($payway) {
                case 'wescan':
                    try {
                        $result = YansongdaPay::wechat($config)->scan($orderData)->toArray();
                        
                        return view('morpho::static_pages.qrpay', [
                            'qr_code' => $result['code_url'],
                            'payname' => $this->payGateway->pay_name,
                            'actual_price' => (float)$this->order->actual_price,
                            'orderid' => $this->order->order_sn,
                        ])->with('title', __('dujiaoka.scan_qrcode_to_pay'));
                        
                    } catch (\Exception $e) {
                        \Log::error('WepayDriver wescan error', ['message' => $e->getMessage()]);
                        throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel'));
                    }
                    break;
                    
                case 'miniapp':
                    try {
                        $openid = request()->input('oid');
                        if (empty($openid) || !preg_match('/^o[A-Za-z0-9_-]{20,30}$/', $openid)) {
                            throw new RuleValidationException('OpenID 参数无效');
                        }
                        
                        $orderData['openid'] = $openid;
                        $result = YansongdaPay::wechat($config)->miniapp($orderData)->toArray();
                        return response()->json($result);
                        
                    } catch (\Exception $e) {
                        \Log::error('WepayDriver miniapp error', ['message' => $e->getMessage()]);
                        throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel'));
                    }
                    break;
                    
                default:
                    throw new RuleValidationException('不支持的支付方式');
            }

        } catch (RuleValidationException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            \Log::error('WepayDriver gateway error', ['message' => $exception->getMessage()]);
            throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    /**
     * 异步通知处理
     */
    public function notify(Request $request): string
    {
        try {
            $config = [
                'app_id' => '',
                'mch_id' => '',
                'key' => '',
            ];

            // 先从 XML body 解析单号，兼容微信 XML 回调（request->all() 对 XML body 无效）
            $xmlBody = $request->getContent();
            $parsed = [];
            if (!empty($xmlBody)) {
                $xml = @simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
                if ($xml !== false) {
                    $parsed = json_decode(json_encode($xml), true);
                }
            }
            $outTradeNo = $parsed['out_trade_no'] ?? ($request->all()['out_trade_no'] ?? null);

            if (empty($outTradeNo)) {
                return 'fail';
            }

            $orderService = app('App\\Services\\Orders');
            $order = $orderService->detailOrderSN($outTradeNo);
            
            if (!$order) {
                return 'fail';
            }

            $payService = app('App\\Services\\Payment');
            $payGateway = $payService->detail($order->pay_id);
            
            if (!$payGateway || $payGateway->pay_handleroute != 'wepay') {
                return 'fail';
            }

            $config = [
                'app_id' => $payGateway->merchant_id,
                'mch_id' => $payGateway->merchant_key,
                'key' => $payGateway->merchant_pem,
            ];

            $result = YansongdaPay::wechat($config)->verify($request->getContent());

            if ($result['return_code'] === 'SUCCESS' && $result['result_code'] === 'SUCCESS') {
                // 验证金额
                $totalFee = $result['total_fee'] / 100;
                app(\App\Services\OrderProcess::class)->completedOrder($result['out_trade_no'], $totalFee, $result['transaction_id']);
                
                return YansongdaPay::wechat($config)->success();
            }

            return 'fail';
            
        } catch (\Exception $e) {
            \Log::error('WepayDriver notify 异常', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 'fail';
        }
    }

    /**
     * 验证支付结果
     */
    public function verify(array $config, Request $request): array
    {
        return [];
    }

    /**
     * 获取支持的支付方式
     */
    public function getSupportedPayways(): array
    {
        return ['wescan', 'miniapp'];
    }

    /**
     * 获取驱动名称
     */
    public function getName(): string
    {
        return 'wepay';
    }

    /**
     * 获取驱动显示名称
     */
    public function getDisplayName(): string
    {
        return '微信支付';
    }
}
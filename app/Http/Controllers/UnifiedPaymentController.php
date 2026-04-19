<?php

namespace App\Http\Controllers;

use App\PaymentGateways\PaymentManager;
use App\Exceptions\RuleValidationException;
use Illuminate\Http\Request;

/**
 * 统一支付控制器
 * 替代原来分散的支付控制器
 */
class UnifiedPaymentController extends Controller
{
    protected PaymentManager $paymentManager;

    public function __construct(PaymentManager $paymentManager)
    {
        $this->paymentManager = $paymentManager;
    }

    /**
     * 支付网关入口
     */
    public function gateway(string $driver, string $payway, string $orderSN)
    {
        $showDetail = config('app.debug') === true || config('app.env') !== 'production';

        try {
            if (!$this->paymentManager->hasDriver($driver)) {
                $hint = $showDetail
                    ? sprintf(' [驱动未注册] driver=%s, 已注册=[%s]', $driver, implode(',', $this->paymentManager->getRegisteredDrivers()))
                    : '';
                throw new RuleValidationException(__('dujiaoka.prompt.payment_driver_not_found') . $hint);
            }

            $paymentDriver = $this->paymentManager->driver($driver);
            
            // 加载订单和支付网关信息
            $orderService = app('App\\Services\\Orders');
            $order = $orderService->detailOrderSN($orderSN);
            
            if (!$order) {
                $hint = $showDetail ? sprintf(' [订单查不到] orderSN=%s', $orderSN) : '';
                throw new RuleValidationException(__('dujiaoka.prompt.order_does_not_exist') . $hint);
            }

            $payService = app('App\\Services\\Payment');
            $payGateway = $payService->detail($order->pay_id);
            
            if (!$payGateway) {
                $hint = $showDetail
                    ? sprintf(' [订单的 pay_id=%s 在 pays 表里没匹配到记录，可能该支付通道已被删除或被禁用]', (string)$order->pay_id)
                    : '';
                throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel') . $hint);
            }

            // 校验 URL 中的 driver 与订单绑定的支付驱动一致，防止跨驱动接管
            if ($driver !== $payGateway->pay_handleroute) {
                $hint = $showDetail
                    ? sprintf(
                        ' [URL 里的 driver=%s 与订单所属支付通道的 pay_handleroute=%s 不一致；请到后台→支付通道，把该通道的「支付处理模块」字段改为 %s]',
                        $driver,
                        (string)$payGateway->pay_handleroute,
                        $driver
                    )
                    : '';
                throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel') . $hint);
            }

            return $paymentDriver->gateway($payway, $orderSN, $order, $payGateway);

        } catch (RuleValidationException $exception) {
            return view('morpho::errors.error', [
                'title' => __('dujiaoka.prompt.payment_error'),
                'content' => $exception->getMessage(),
                'url' => null
            ]);
        } catch (\Throwable $exception) {
            $detail = $showDetail
                ? sprintf(
                    ' [%s] %s @ %s:%d',
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                )
                : '';

            try {
                \Log::error('UnifiedPaymentController gateway error', [
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);
            } catch (\Throwable $ignored) {
            }

            return view('morpho::errors.error', [
                'title' => __('dujiaoka.prompt.payment_error'),
                'content' => __('dujiaoka.prompt.system_error') . $detail,
                'url' => null
            ]);
        }
    }

    /**
     * 支付异步通知
     */
    public function notify(Request $request, string $driver)
    {
        try {
            if (!$this->paymentManager->hasDriver($driver)) {
                return 'error';
            }

            $paymentDriver = $this->paymentManager->driver($driver);
            return $paymentDriver->notify($request);

        } catch (\Exception $exception) {
            \Log::error("Payment notify error for driver {$driver}: " . $exception->getMessage());
            return 'error';
        }
    }

    /**
     * 支付返回页面
     */
    public function return(Request $request, string $driver)
    {
        try {
            if (!$this->paymentManager->hasDriver($driver)) {
                return redirect()->route('home')->with('error', __('dujiaoka.prompt.payment_driver_not_found'));
            }

            $orderSN = $request->input('orderSN') ?? $request->input('out_trade_no');

            if ($orderSN && preg_match('/^[A-Za-z0-9]{1,64}$/', $orderSN)) {
                return redirect('/order/detail/' . $orderSN);
            }

            return redirect()->route('home');

        } catch (\Exception $exception) {
            return redirect()->route('home')->with('error', __('dujiaoka.prompt.system_error'));
        }
    }

    /**
     * 获取所有支付驱动信息（用于管理后台）
     */
    public function getDriversInfo()
    {
        return response()->json([
            'drivers' => $this->paymentManager->getAllDriversInfo()
        ]);
    }
}
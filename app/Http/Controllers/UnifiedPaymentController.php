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
        // 诊断信息始终显示：这些 hint 只暴露业务上下文（driver/handleroute/订单号），
        // 不包含任何敏感凭证，可安全在 production 展示给用户以便自助排障
        try {
            if (!$this->paymentManager->hasDriver($driver)) {
                $hint = sprintf(
                    ' [驱动未注册] driver=%s, 已注册=[%s]',
                    $driver,
                    implode(',', $this->paymentManager->getRegisteredDrivers())
                );
                throw new RuleValidationException(__('dujiaoka.prompt.payment_driver_not_found') . $hint);
            }

            $paymentDriver = $this->paymentManager->driver($driver);

            $orderService = app('App\\Services\\Orders');
            $order = $orderService->detailOrderSN($orderSN);

            if (!$order) {
                $hint = sprintf(' [订单查不到] orderSN=%s', $orderSN);
                throw new RuleValidationException(__('dujiaoka.prompt.order_does_not_exist') . $hint);
            }

            $payService = app('App\\Services\\Payment');
            $payGateway = $payService->detail($order->pay_id);

            if (!$payGateway) {
                $hint = sprintf(
                    ' [订单的 pay_id=%s 在 pays 表里没匹配到记录，可能该支付通道已被删除或被禁用]',
                    (string) $order->pay_id
                );
                throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel') . $hint);
            }

            if ($driver !== $payGateway->pay_handleroute) {
                $hint = sprintf(
                    ' [URL 里的 driver=%s 与订单所属支付通道的 pay_handleroute=%s 不一致；请到后台→支付通道，把该通道「支付处理模块」改为 %s，并清一次 pay_methods 缓存 (php artisan optimize:clear)]',
                    $driver,
                    (string) $payGateway->pay_handleroute,
                    $driver
                );
                throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel') . $hint);
            }

            return $paymentDriver->gateway($payway, $orderSN, $order, $payGateway);

        } catch (RuleValidationException $exception) {
            return view('morpho::errors.error', [
                'title'   => __('dujiaoka.prompt.payment_error'),
                'content' => $exception->getMessage(),
                'url'     => null,
            ]);
        } catch (\Throwable $exception) {
            // 未预期的异常：把关键定位信息（class + message + file:line）显示给用户
            $detail = sprintf(
                ' [%s] %s @ %s:%d',
                get_class($exception),
                $exception->getMessage(),
                basename($exception->getFile()),
                $exception->getLine()
            );

            try {
                \Log::error('UnifiedPaymentController gateway error', [
                    'driver'    => $driver,
                    'payway'    => $payway,
                    'orderSN'   => $orderSN,
                    'message'   => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'file'      => $exception->getFile(),
                    'line'      => $exception->getLine(),
                    'trace'     => $exception->getTraceAsString(),
                ]);
            } catch (\Throwable $ignored) {
            }

            return view('morpho::errors.error', [
                'title'   => __('dujiaoka.prompt.payment_error'),
                'content' => __('dujiaoka.prompt.system_error') . $detail,
                'url'     => null,
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
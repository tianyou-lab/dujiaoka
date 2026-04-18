<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentDriverInterface;
use App\Models\Order;
use App\Models\Pay;
use App\Exceptions\RuleValidationException;
use App\Services\Orders;
use App\Services\Payment;
use App\Services\OrderProcess;
use Illuminate\Http\Request;

/**
 * 支付驱动抽象基类
 * 提供通用的支付处理逻辑
 */
abstract class AbstractPaymentDriver implements PaymentDriverInterface
{
    /**
     * 当前订单
     */
    protected Order $order;

    /**
     * 支付网关配置
     */
    protected Pay $payGateway;

    /**
     * 加载网关信息
     */
    protected function loadGateway(string $orderSN, string $payway): void
    {
        $orderService = app(Orders::class);
        $this->order = $orderService->detailOrderSN($orderSN);

        if (!$this->order) {
            throw new RuleValidationException(__('dujiaoka.prompt.order_does_not_exist'));
        }

        $payService = app(Payment::class);
        $this->payGateway = $payService->detail($this->order->pay_id);

        if (!$this->payGateway) {
            throw new RuleValidationException(__('dujiaoka.prompt.abnormal_payment_channel'));
        }

        // 验证支付方式是否支持
        if (!in_array($payway, $this->getSupportedPayways())) {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_method_not_supported'));
        }
    }

    /**
     * 渲染支付页面的通用方法
     */
    protected function render(string $template, array $data = [], string $title = ''): \Illuminate\View\View
    {
        return view($template, $data)->with('page_title', $title);
    }

    /**
     * 返回错误信息的通用方法
     */
    protected function err(string $message): \Illuminate\View\View
    {
        return view('morpho::errors.error', ['title' => __('dujiaoka.prompt.error'), 'content' => $message, 'url' => null]);
    }

    /**
     * 生成通知URL
     */
    protected function getNotifyUrl(): string
    {
        return url("/pay/{$this->getName()}/notify");
    }

    /**
     * 生成返回URL
     */
    protected function getReturnUrl(string $orderSN): string
    {
        return url('/order/detail/' . $orderSN);
    }

    /**
     * 验证订单状态
     */
    protected function validateOrderStatus(): void
    {
        if ($this->order->status !== Order::STATUS_WAIT_PAY) {
            throw new RuleValidationException(__('dujiaoka.prompt.order_status_invalid'));
        }
    }

    /**
     * 处理支付完成
     */
    protected function processPaymentSuccess(string $orderSN, float $amount, string $tradeNo): void
    {
        app(OrderProcess::class)->completedOrder($orderSN, $amount, $tradeNo);
    }

    /**
     * 获取基础订单信息
     */
    protected function getOrderInfo(): array
    {
        return [
            'out_trade_no' => $this->order->order_sn,
            'total_amount' => (float)$this->order->actual_price,
            'subject' => $this->order->order_sn,
            'notify_url' => $this->getNotifyUrl(),
            'return_url' => $this->getReturnUrl($this->order->order_sn),
        ];
    }
}
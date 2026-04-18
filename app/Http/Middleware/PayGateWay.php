<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Order;
use App\Services\Orders as OrdersService;

class PayGateWay
{

    /**
     * 仅允许 WAIT_PAY 状态的订单进入第三方支付网关。
     * 余额全额支付走 completedOrderByBalance 独立路径，不经过此中间件。
     */
    public function handle($request, Closure $next)
    {
        $orderSN = $request->input('orderSN') ?? $request->route('orderSN');
        if ($orderSN) {
            if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $orderSN)) {
                return response()->json(['message' => __('dujiaoka.prompt.order_status_invalid')], 422);
            }
            $order = Order::where('order_sn', $orderSN)->first();
            if (!$order || $order->status !== Order::STATUS_WAIT_PAY) {
                return response()->json(['message' => __('dujiaoka.prompt.order_status_invalid')], 422);
            }
        }
        return $next($request);
    }
}

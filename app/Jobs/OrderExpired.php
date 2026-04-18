<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Jobs\CouponBack;
use App\Services\CacheManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderExpired implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 3;

    /**
     * 任务可以执行的最大秒数 (超时时间)。
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * 订单号
     * @var string
     */
    private $orderSN;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $orderSN)
    {
        $this->orderSN = $orderSN;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 原子条件更新：只有 WAIT_PAY 才能迁移到 EXPIRED，防止并发重复执行
        $affected = Order::where('order_sn', $this->orderSN)
            ->where('status', Order::STATUS_WAIT_PAY)
            ->update(['status' => Order::STATUS_EXPIRED]);

        if (!$affected) {
            // 订单已被支付或已过期，幂等退出
            return;
        }

        // 批量 update 不触发 Eloquent 事件，手动清缓存
        CacheManager::forgetOrder($this->orderSN);

        // 状态已原子迁移，安全执行后续副作用
        $order = Order::where('order_sn', $this->orderSN)->first();
        if (!$order) {
            return;
        }

        $stockMode = cfg('stock_mode', 2);
        if ($stockMode == 1) {
            CacheManager::unlockStock($this->orderSN);
        }

        // 退还预扣余额（幂等：related_order_sn 唯一约束由 addBalance 流水记录保证可追溯）
        if (in_array($order->payment_method, [Order::PAYMENT_BALANCE, Order::PAYMENT_MIXED])
            && $order->balance_used > 0
            && $order->user_id
        ) {
            $user = User::find($order->user_id);
            if ($user) {
                $user->addBalance(
                    $order->balance_used,
                    'refund',
                    '订单过期退款',
                    $order->order_sn
                );
            }
        }

        CouponBack::dispatch($order);
    }
}

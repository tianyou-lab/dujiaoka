<?php

namespace App\Console\Commands;

use App\Models\VmqPayOrder;
use App\Models\VmqSetting;
use App\Models\VmqTmpPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VmqCloseExpiredCommand extends Command
{
    protected $signature = 'vmq:close-expired {--dry-run : 仅显示不写库}';

    protected $description = '关闭已过期的 V免签 订单并释放金额锁（建议 crontab 每分钟运行一次）';

    public function handle(): int
    {
        $closeMinutes = (int) VmqSetting::get('close_minutes', '10');
        if ($closeMinutes <= 0) {
            $closeMinutes = 10;
        }
        $threshold = time() - $closeMinutes * 60;

        $expiredOrders = VmqPayOrder::where('state', VmqPayOrder::STATE_WAIT)
            ->where('create_date', '<', $threshold)
            ->limit(500)
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('无过期订单需要关闭');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        foreach ($expiredOrders as $order) {
            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 将关闭 order=%s vmq_order=%s price=%s age=%ds',
                    $order->order_sn,
                    $order->vmq_order_id,
                    $order->really_price,
                    time() - (int) $order->create_date
                ));
                continue;
            }

            try {
                DB::transaction(function () use ($order, &$closed) {
                    $affected = VmqPayOrder::where('id', $order->id)
                        ->where('state', VmqPayOrder::STATE_WAIT)
                        ->update([
                            'state'      => VmqPayOrder::STATE_CLOSED,
                            'close_date' => time(),
                        ]);

                    if ($affected) {
                        VmqTmpPrice::where('vmq_order_id', $order->vmq_order_id)->delete();
                        $closed++;
                    }
                });
            } catch (\Throwable $e) {
                $this->error(sprintf('关闭失败 id=%d: %s', $order->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('本次共关闭 %d 条过期订单（阈值 %d 分钟）', $closed, $closeMinutes));

        return self::SUCCESS;
    }
}

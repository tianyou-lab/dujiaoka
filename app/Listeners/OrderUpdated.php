<?php

namespace App\Listeners;

use App\Jobs\MailSend;
use App\Models\BaseModel;
use App\Models\Emailtpl;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Events\OrderUpdated as OrderUpdatedEvent;

class OrderUpdated
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(OrderUpdatedEvent $event)
    {
        // 只在 status 字段实际发生变更时才处理邮件通知，避免修改其他字段时重复发邮件
        if (!$event->order->wasChanged('status')) {
            return;
        }

        $sysCache = cache('system-setting');
        $firstItem = $event->order->orderItems->first();
        if (!$firstItem) {
            return;
        }
        $order = [
            'created_at' => date('Y-m-d H:i'),
            'ord_title' => $firstItem->goods_name ?? '未知商品',
            'webname' => $sysCache['text_logo'] ?? '启航数卡',
            'weburl' => config('app.url'),
            'order_id' => $event->order->order_sn,
            'ord_price' => $event->order->actual_price,
            'ord_info' => str_replace(PHP_EOL, '<br/>', $firstItem->info ?? ''),
        ];
        $to = $event->order->email;
        $isManual = $event->order->orderItems->contains(function ($item) {
            return $item->type == BaseModel::MANUAL_PROCESSING;
        });
        // 邮件
        if ($isManual) {
            $tplMap = [
                Order::STATUS_PENDING   => ['key' => 'email_template_pending_order',   'token' => 'pending_order'],
                Order::STATUS_COMPLETED => ['key' => 'email_template_completed_order', 'token' => 'completed_order'],
                Order::STATUS_FAILURE   => ['key' => 'email_template_failed_order',    'token' => 'failed_order'],
            ];
            $tplDef = $tplMap[$event->order->status] ?? null;
            if ($tplDef) {
                $tplModel = cache()->remember($tplDef['key'], 86400, function () use ($tplDef) {
                    return Emailtpl::query()->where('tpl_token', $tplDef['token'])->first();
                });
                if ($tplModel) {
                    self::sendMailToOrderStatus($tplModel->toArray(), $order, $to);
                }
            }
        }
    }


    /**
     * 邮件发送
     *
     * @param array $mailtpl 模板
     * @param array $order 内容
     * @param string $to 接受者
     *
     */
    private static function sendMailToOrderStatus(array $mailtpl, array $order, string $to) :void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $info = replaceMailTemplate($mailtpl, $order);
            MailSend::dispatch($to, $info['tpl_name'], $info['tpl_content']);
        }
    }
}

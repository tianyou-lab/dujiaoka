<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class TelegramPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * @var Order
     */
    private $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $firstItem = $this->order->orderItems->first();
        $formatText = '*'. __('dujiaoka.prompt.new_order_push').'('.$this->order->actual_price.'元)*' . "\n"
        . __('order.fields.order_id') .': `'.$this->order->id.'`' . "\n"
        . __('order.fields.order_sn') .': `'.$this->order->order_sn.'`' . "\n"
        . __('order.fields.pay_id') .': `'.($this->order->pay->pay_name ?? '').'`' . "\n"
        . __('order.fields.title') .': '.($firstItem->goods_name ?? '未知商品') . "\n"
        . __('order.fields.actual_price') .': '.$this->order->actual_price . "\n"
        . __('order.fields.email') .': `'.$this->order->email.'`' . "\n"
        . __('order.fields.order_created') .': '.$this->order->created_at;
        $client = new Client([
            'timeout' => 30,
            'proxy'=> ''
        ]);
        $apiUrl = 'https://api.telegram.org/bot' . cfg('telegram_bot_token') . '/sendMessage';
        try {
            $client->post($apiUrl, [
                'json' => [
                    'chat_id' => cfg('telegram_userid'),
                    'parse_mode' => 'Markdown',
                    'text' => $formatText,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('TelegramPush 发送失败', ['error' => $e->getMessage()]);
        }
    }
}

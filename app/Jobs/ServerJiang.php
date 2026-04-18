<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerJiang implements ShouldQueue
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

    /**
     * Create a new job instance.
     *
     * @return void
     */
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
        $apiToken = cfg('server_jiang_token');
        if (empty($apiToken)) {
            return;
        }

        $postdata = [
            'text' => __('dujiaoka.prompt.new_order_push') . ":{$this->order->order_sn}",
            'desp' => "- " . __('order.fields.title') . "：" . ($this->order->orderItems->first()->goods_name ?? '未知商品') . "\n"
                . "- " . __('order.fields.order_sn') . "：{$this->order->order_sn}\n"
                . "- " . __('order.fields.email') . "：{$this->order->email}\n"
                . "- " . __('order.fields.actual_price') . "：{$this->order->actual_price}",
        ];

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10, 'verify' => true]);
            $client->post('https://sctapi.ftqq.com/' . $apiToken . '.send', [
                'form_params' => $postdata,
            ]);
        } catch (\Throwable $e) {
            \Log::error('ServerJiang 推送失败', ['error' => $e->getMessage()]);
        }
    }
}

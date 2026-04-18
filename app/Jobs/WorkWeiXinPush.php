<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class WorkWeiXinPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 1;

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
        $key = cfg('qywxbot_key');
        if (empty($key)) {
            return;
        }

        $firstItem = $this->order->orderItems->first();
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=' . $key;
        $params = [
            "msgtype" => "markdown",
            "markdown" => [
                "content" => __('dujiaoka.prompt.new_order_push').'(<font color="warning">'.$this->order->actual_price."</font>元)\n"
                    .'>'.__('order.fields.order_id') .': <font color="comment">'.$this->order->id."</font>\n"
                    .'>'.__('order.fields.order_sn') .': <font color="comment">'.$this->order->order_sn."</font>\n"
                    .'>'.__('order.fields.pay_id') .': <font color="comment">'.($this->order->pay->pay_name ?? '')."</font>\n"
                    .'>'.__('order.fields.title') .': <font color="comment">'.($firstItem->goods_name ?? '未知商品')."</font>\n"
                    .'>'.__('order.fields.actual_price') .': <font color="comment">'.$this->order->actual_price."</font>\n"
                    .'>'.__('order.fields.email') .': <font color="comment">'.$this->order->email."</font>\n"
                    .'>'.__('order.fields.order_created') .': <font color="comment">'.$this->order->created_at."</font>"
            ]
        ];

        try {
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $client->post($apiUrl, ['json' => $params]);
        } catch (\Throwable $e) {
            \Log::error('WorkWeiXinPush 发送失败', ['error' => $e->getMessage()]);
        }
    }
}

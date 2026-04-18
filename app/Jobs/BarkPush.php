<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\BaseModel;


class BarkPush implements ShouldQueue
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
        $barkServer = cfg('bark_server');
        $barkToken = cfg('bark_token');
        if (empty($barkServer) || empty($barkToken)) {
            return;
        }

        $parsed = parse_url($barkServer);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            \Log::error('BarkPush bark_server 配置异常', ['url' => $barkServer]);
            return;
        }

        $firstItem = $this->order->orderItems->first();
        $apiUrl = rtrim($barkServer, '/') . '/' . $barkToken;
        $params = [
            "title" => __('dujiaoka.prompt.new_order_push').'('.$this->order->actual_price.'元)',
            "body" => __('order.fields.order_id') .': '.$this->order->id."\n"
                . __('order.fields.order_sn') .': '.$this->order->order_sn."\n"
                . __('order.fields.pay_id') .': '.($this->order->pay->pay_name ?? '')."\n"
                . __('order.fields.title') .': '.($firstItem->goods_name ?? '未知商品')."\n"
                . __('order.fields.actual_price') .': '.$this->order->actual_price."\n"
                . __('order.fields.email') .': '.$this->order->email."\n"
                . __('order.fields.order_created') .': '.$this->order->created_at,
            "icon" => url('assets/common/images/default.jpg'),
            "level" => "timeSensitive",
            "group" => cfg('text_logo', '启航数卡')
        ];
        if (cfg('is_open_bark_push_url', 0) == BaseModel::STATUS_OPEN) {
            $params["url"] = url('/order/detail/' . $this->order->order_sn);
        }

        try {
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $client->post($apiUrl, ['form_params' => $params]);
        } catch (\Throwable $e) {
            \Log::error('BarkPush 发送失败', ['error' => $e->getMessage()]);
        }
    }
}

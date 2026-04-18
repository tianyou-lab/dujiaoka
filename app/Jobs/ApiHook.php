<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\RemoteServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApiHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 30;

    private $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle()
    {
        foreach ($this->order->orderItems as $item) {
            $goods = $item->goods;
            if (!$goods || empty($goods->api_hook)) {
                continue;
            }

            $server = RemoteServer::find((int)$goods->api_hook);
            if (!$server || !$server->is_active) {
                continue;
            }

            if ($server->type === RemoteServer::HTTP_SERVER && !empty($server->url)) {
                if (!self::isSafeUrl($server->url)) {
                    Log::warning("ApiHook: 拒绝不安全的 URL", ['url' => $server->url, 'server_id' => $server->id]);
                    continue;
                }

                $postdata = [
                    'title'        => $item->goods_name,
                    'order_sn'     => $this->order->order_sn,
                    'email'        => $this->order->email,
                    'actual_price' => $this->order->actual_price,
                    'order_info'   => $item->info ?? '',
                    'good_id'      => $goods->id,
                    'gd_name'      => $goods->gd_name,
                ];
                $headers = array_merge(['Content-type: application/json'], array_map(
                    fn($k, $v) => "{$k}: {$v}",
                    array_keys($server->headers ?? []),
                    array_values($server->headers ?? [])
                ));
                $opts = [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => implode("\r\n", $headers),
                        'content' => json_encode($postdata, JSON_UNESCAPED_UNICODE),
                        'timeout' => 10,
                        'follow_location' => 0,
                        'max_redirects' => 0,
                    ]
                ];
                try {
                    file_get_contents($server->url, false, stream_context_create($opts));
                } catch (\Throwable $e) {
                    Log::error("ApiHook: 请求失败", ['url' => $server->url, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if (empty($records)) {
                return false;
            }
            $ips = array_map(fn($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records);
            $ips = array_filter($ips);
            if (empty($ips)) {
                return false;
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

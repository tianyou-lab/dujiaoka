<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * 仅信任已知反代 IP，避免全量信任导致 IP 伪造。
     * 生产环境请在 .env 中设置 TRUSTED_PROXIES（逗号分隔），或改为具体 IP/CIDR。
     *
     * @var array|string|null
     */
    protected $proxies = null; // 由 env('TRUSTED_PROXIES') 驱动，见 boot()

    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $trusted = env('TRUSTED_PROXIES', '');
        if ($trusted === '*') {
            $this->proxies = '*';
        } elseif (!empty($trusted)) {
            $this->proxies = array_map('trim', explode(',', $trusted));
        }
    }
}

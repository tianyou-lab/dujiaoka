<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * 信任反向代理发送的 X-Forwarded-* 头。
 *
 * dujiaoka 基本都部署在 Nginx + 可选 Cloudflare/CDN 之后，如果这里不信任代理：
 *   - Laravel 看到的 scheme 始终是 http（即使用户用的是 https 访问 CDN）
 *   - Laravel 生成的重定向 Location 都是 http://，再被 Nginx 强制跳 https
 *   - 结果就是浏览器出现 ERR_TOO_MANY_REDIRECTS
 *
 * 默认信任所有代理（`*`），这是官方 Laravel 模板（5.8+）的推荐做法；
 * 想严格限制只信任特定 IP/CIDR 的用户，可以在 .env 中设置 TRUSTED_PROXIES=1.2.3.4,5.6.7.0/24。
 */
class TrustProxies extends Middleware
{
    /**
     * 默认信任所有代理，避免 CDN + Nginx 场景下出现 ERR_TOO_MANY_REDIRECTS。
     * 如需收紧，可在 .env 中覆盖 TRUSTED_PROXIES 环境变量。
     *
     * @var array|string|null
     */
    protected $proxies = '*';

    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $trusted = env('TRUSTED_PROXIES');
        if ($trusted === null || $trusted === '') {
            return;
        }
        if ($trusted === '*') {
            $this->proxies = '*';
        } else {
            $this->proxies = array_map('trim', explode(',', $trusted));
        }
    }
}

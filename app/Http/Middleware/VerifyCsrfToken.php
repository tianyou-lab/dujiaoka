<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'pay/*',
        'install',
        // 嵌入式 V免签 App/第三方 API 端点（不能携带 CSRF token）
        'createOrder',
        'checkOrder',
        'getOrder',
        'appHeart',
        'appPush',
        'getState',
        'pay/vmq/*',
    ];
}

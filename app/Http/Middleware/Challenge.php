<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\BaseModel;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

class Challenge
{
    private $whiteClass = [
        "App\Http\Controllers\PayController",
        "App\Http\Controllers\UnifiedPaymentController",
    ];

    // 支付回调路径前缀白名单（处理无控制器命名空间的情况）
    private $whitePaths = [
        'pay/',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $routeAction = $request->route()->getAction();
        $controller = $routeAction['controller'] ?? null;
        if ($controller && in_array(explode('@', $controller)[0], $this->whiteClass)) {
            return $next($request);
        }

        // 支付回调路径豁免
        $path = ltrim($request->path(), '/');
        foreach ($this->whitePaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        if (cfg('is_cn_challenge') == BaseModel::STATUS_CLOSE) {
            return $next($request);
        }
            
        $status = session('challenge');
        if($status === "pass")
            return $next($request);

        if($request->has('_challenge')){
            $expected = session('challenge_hash');
            if ($expected && hash_equals($expected, substr(sha1($request->input('_challenge')), -8))){
                session(['challenge' => 'pass']);
                session()->forget('challenge_hash');
                return $next($request);
            }
        }
        
        $isoCode = getIpCountry($request->ip());
        if($isoCode != 'CN'){
            session(['challenge' => 'pass']);
            return $next($request);
        }
        $challenge = substr(sha1(random_bytes(16)), -8);
        session(['challenge_hash' => $challenge]);
        return response()->view('common/challenge',['code' => $challenge]);
    }
}

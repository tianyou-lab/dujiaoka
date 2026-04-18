<?php

namespace App\Http\Middleware;

use App\Models\BaseModel;
use Closure;

class DujiaoBoot
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        
        // 安装检查
        $installLock = base_path() . DIRECTORY_SEPARATOR . 'install.lock';
        if (!file_exists($installLock)) {
            return redirect(url('install'));
        }
        
        // 语言检测
        try {
            $lang = cfg('language', 'zh_CN');
            // 兼容旧格式 zh-CN → zh_CN
            $lang = str_replace('-', '_', $lang);
            app()->setLocale($lang);
        } catch (\Exception $e) {
            app()->setLocale('zh_CN');
        }
        
        return $next($request);
    }
}

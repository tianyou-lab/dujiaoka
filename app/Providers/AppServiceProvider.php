<?php

namespace App\Providers;

use App\Services\Cards;
use App\Services\Coupons;
use App\Services\Email;
use App\Services\Shop;
use App\Services\OrderProcess;
use App\Services\Orders;
use App\Services\Payment;
use App\Services\Validator;
use App\Services\CacheManager;
use Illuminate\Support\ServiceProvider;
use Jenssegers\Agent\Agent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Shop::class);
        $this->app->singleton(Payment::class);
        $this->app->singleton(Cards::class);
        $this->app->singleton(Orders::class);
        $this->app->singleton(Coupons::class);
        $this->app->bind(OrderProcess::class);
        $this->app->singleton(Email::class);
        $this->app->singleton(Validator::class);
        $this->app->singleton(CacheManager::class);
        $this->app->singleton('Jenssegers\Agent', function () {
            return $this->app->make(Agent::class);
        });

        $this->app->singleton('App\\Services\\ConfigService', function ($app) {
            return new \App\Services\ConfigService();
        });
        
        $this->app->singleton('App\\Services\\ThemeService');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // 动态设置语言文件中的货币符号
        $this->app->booted(function () {
            $currency = shop_cfg('currency', 'cny');
            $symbols = [
                'cny' => '¥',
                'usd' => '$',
            ];
            $symbol = $symbols[$currency] ?? '¥';
            
            // 设置所有语言文件的货币符号
            // 注意：必须先 trans() 触发 file 加载，否则 addLines 会把整个 group
            // 标成 "loaded"，结果其它 key 永远拿不到，前端全是 raw key
            $translator = app('translator');
            foreach (['zh_CN', 'zh_TW', 'en'] as $loc) {
                $translator->get('dujiaoka.money_symbol', [], $loc);
                $translator->addLines(['dujiaoka.money_symbol' => $symbol], $loc);
            }
        });
    }
}

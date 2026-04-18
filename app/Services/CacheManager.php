<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache as LaravelCache;

/**
 * 统一缓存服务
 */
class CacheManager
{
    /**
     * 商品详情缓存时间（秒）- 6小时
     */
    const GOODS_CACHE_TIME = 21600;

    /**
     * 订单详情缓存时间（秒）- 1小时
     */
    const ORDER_CACHE_TIME = 3600;

    /**
     * 统计数据缓存时间（秒）- 30分钟
     */
    const STATS_CACHE_TIME = 1800;

    /**
     * 库存锁定缓存时间（秒）- 动态跟随订单过期配置，最少 300 秒
     */
    private static function stockLockTtl(): int
    {
        return max(300, (int)cfg('order_expire_time', 5) * 60);
    }

    /**
     * 生成商品缓存键
     */
    public static function goodsKey(int $id): string
    {
        return "goods_detail_{$id}";
    }

    /**
     * 生成订单缓存键
     */
    public static function orderKey(string $orderSn): string
    {
        return "order_detail_{$orderSn}";
    }

    /**
     * 生成统计缓存键
     */
    public static function statsKey(string $type): string
    {
        return "admin_stats_{$type}";
    }

    /**
     * 缓存商品数据
     */
    public static function rememberGoods(int $id, \Closure $callback)
    {
        return LaravelCache::remember(
            self::goodsKey($id),
            self::GOODS_CACHE_TIME,
            $callback
        );
    }

    /**
     * 缓存订单数据
     */
    public static function rememberOrder(string $orderSn, \Closure $callback)
    {
        return LaravelCache::remember(
            self::orderKey($orderSn),
            self::ORDER_CACHE_TIME,
            $callback
        );
    }

    /**
     * 缓存统计数据
     */
    public static function rememberStats(string $type, \Closure $callback)
    {
        return LaravelCache::remember(
            self::statsKey($type),
            self::STATS_CACHE_TIME,
            $callback
        );
    }

    /**
     * 清除商品缓存
     */
    public static function forgetGoods(int $id): void
    {
        LaravelCache::forget(self::goodsKey($id));
    }

    /**
     * 清除订单缓存
     */
    public static function forgetOrder(string $orderSn): void
    {
        LaravelCache::forget(self::orderKey($orderSn));
    }

    /**
     * 清除统计缓存
     */
    public static function forgetStats(string $type): void
    {
        LaravelCache::forget(self::statsKey($type));
    }

    /**
     * 清除所有统计缓存
     */
    public static function forgetAllStats(): void
    {
        LaravelCache::forget(self::statsKey('overview'));
        LaravelCache::forget(self::statsKey('daily'));
        LaravelCache::forget(self::statsKey('monthly'));
    }

    /**
     * 清除邮件模板缓存
     */
    public static function forgetEmailTemplate(string $token): void
    {
        LaravelCache::forget("email_template_{$token}");
    }

    /**
     * 清除所有邮件模板缓存
     */
    public static function forgetAllEmailTemplates(): void
    {
        $tokens = \App\Models\Emailtpl::pluck('tpl_token')->toArray();
        $defaultTokens = ['pending_order', 'completed_order', 'failed_order', 'manual_send_manage_mail', 'card_send_user_email'];
        $allTokens = array_unique(array_merge($defaultTokens, $tokens));

        foreach ($allTokens as $token) {
            LaravelCache::forget("email_template_{$token}");
        }
    }

    /**
     * 清除支付方式缓存
     */
    public static function forgetPayMethods(): void
    {
        LaravelCache::forget('enabled_pay_methods');
        // 注意：单个支付方式缓存会在具体编辑时通过 forgetPayMethod() 清除
    }

    /**
     * 清除单个支付方式缓存
     */
    public static function forgetPayMethod(int $payId): void
    {
        LaravelCache::forget("pay_method_{$payId}");
    }

    /**
     * 清除商品相关缓存（扩展原有方法）
     */
    public static function forgetGoodsWithSub(int $goodsId): void
    {
        LaravelCache::forget("goods_with_sub_{$goodsId}");
        self::forgetGoods($goodsId); // 清除原有商品缓存
    }

    /**
     * 生成库存锁定缓存键
     */
    public static function stockLockKey(int $subId): string
    {
        return "stock_lock_{$subId}";
    }

    /**
     * 生成订单库存锁定缓存键
     */
    public static function orderStockLockKey(string $orderSn): string
    {
        return "order_stock_lock_{$orderSn}";
    }

    /**
     * 检查缓存驱动是否支持原子操作，非 redis/memcached 等驱动下库存锁不安全
     */
    private static function assertAtomicDriver(): void
    {
        $driver = config('cache.default');
        if (in_array($driver, ['file', 'array', 'null'])) {
            \Illuminate\Support\Facades\Log::warning(
                "库存锁定使用了 [{$driver}] 缓存驱动，并发场景下不安全，建议切换到 redis 或 memcached"
            );
        }
    }

    /**
     * 简单锁定库存（下单即减库存模式）
     */
    public static function lockStock(int $subId, int $quantity, string $orderSn): bool
    {
        self::assertAtomicDriver();
        $lockKey = self::stockLockKey($subId);
        $orderStockKey = self::orderStockLockKey($orderSn);

        // increment 不会自动设置 TTL，需要在 key 不存在时先初始化带 TTL 的值
        if (!LaravelCache::has($lockKey)) {
            LaravelCache::put($lockKey, 0, self::stockLockTtl());
        }
        LaravelCache::increment($lockKey, $quantity);

        // 记录订单锁定的商品信息（用于释放）
        $orderStock = LaravelCache::get($orderStockKey, []);
        $orderStock[] = ['sub_id' => $subId, 'quantity' => $quantity];
        LaravelCache::put($orderStockKey, $orderStock, self::stockLockTtl());

        return true;
    }

    /**
     * 简单释放库存锁定（订单过期或取消）
     */
    public static function unlockStock(string $orderSn): bool
    {
        $orderStockKey = self::orderStockLockKey($orderSn);
        $orderStock = LaravelCache::get($orderStockKey, []);
        
        foreach ($orderStock as $item) {
            $lockKey = self::stockLockKey($item['sub_id']);
            $current = LaravelCache::decrement($lockKey, $item['quantity']);
            if ($current < 0) {
                LaravelCache::put($lockKey, 0, self::stockLockTtl());
            }
        }
        
        LaravelCache::forget($orderStockKey);
        return true;
    }

    /**
     * 获取已锁定的库存数量
     */
    public static function getLockedStock(int $subId): int
    {
        return (int) LaravelCache::get(self::stockLockKey($subId), 0);
    }

    /**
     * 检查库存是否足够（考虑锁定库存）
     */
    public static function checkStockAvailable(int $subId, int $requestQuantity, int $actualStock): bool
    {
        $availableStock = $actualStock - self::getLockedStock($subId);
        return $availableStock >= $requestQuantity;
    }

    /**
     * 原子"检查+锁定"库存，防止并发超卖。
     * 使用 Cache lock 保证同一 subId 同一时刻只有一个请求执行检查+锁定。
     */
    public static function checkAndLockStock(int $subId, int $quantity, int $actualStock, string $orderSn): bool
    {
        $mutex = LaravelCache::lock("stock_mutex_{$subId}", 5);
        if (!$mutex->get()) {
            return false;
        }
        try {
            if (!self::checkStockAvailable($subId, $quantity, $actualStock)) {
                return false;
            }
            self::lockStock($subId, $quantity, $orderSn);
            return true;
        } finally {
            $mutex->release();
        }
    }
}
<?php

namespace App\Models;

use App\Services\CacheManager;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Pay extends BaseModel
{

    use SoftDeletes;

    protected $table = 'pays';

    protected $fillable = [
        'pay_name',
        'pay_check',
        'pay_fee',
        'pay_method',
        'pay_client',
        'merchant_id',
        'merchant_key',
        'merchant_pem',
        'app_public_cert',
        'alipay_public_cert',
        'alipay_root_cert',
        'pay_handleroute',
        'china_only',
        'enable',
    ];

    // 状态常量
    const ENABLED = 1;
    const DISABLED = 0;

    // 支付方式
    const METHOD_JUMP = 1;  // 跳转
    const METHOD_SCAN = 2;  // 扫码

    // 客户端类型
    const CLIENT_PC = 1;     // 电脑
    const CLIENT_MOBILE = 2; // 手机  
    const CLIENT_ALL = 3;    // 通用

    public static function getMethodMap()
    {
        return [
            self::METHOD_JUMP => __('pay.fields.method_jump'),
            self::METHOD_SCAN => __('pay.fields.method_scan'),
        ];
    }

    public static function getClientMap()
    {
        return [
            self::CLIENT_PC => __('pay.fields.pay_client_pc'),
            self::CLIENT_MOBILE => __('pay.fields.pay_client_mobile'),
            self::CLIENT_ALL => __('pay.fields.pay_client_all'),
        ];
    }

    // 作用域：获取启用的支付方式
    public function scopeEnabled($query)
    {
        return $query->where('enable', self::ENABLED);
    }

    // 作用域：根据客户端类型筛选
    public function scopeForClient($query, $client)
    {
        return $query->whereIn('pay_client', [$client, self::CLIENT_ALL]);
    }

    /**
     * 这些字段在老版表结构里声明为 NOT NULL（无默认值）。
     * Laravel 的 ConvertEmptyStringsToNull 中间件会把表单空输入改成 null，
     * 这里统一在保存前回填空字符串，避免清空 merchant_key / merchant_pem
     * 等字段时抛 1048 Integrity constraint violation。
     */
    protected static array $stringNullFallback = [
        'merchant_id',
        'merchant_key',
        'merchant_pem',
        'app_public_cert',
        'alipay_public_cert',
        'alipay_root_cert',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $pay) {
            foreach (self::$stringNullFallback as $column) {
                if ($pay->isDirty($column) && is_null($pay->{$column})) {
                    $pay->{$column} = '';
                }
            }
        });

        static::updated(function ($pay) {
            CacheManager::forgetPayMethod($pay->id);
            CacheManager::forgetPayMethods();
        });

        static::deleted(function ($pay) {
            CacheManager::forgetPayMethod($pay->id);
            CacheManager::forgetPayMethods();
        });
    }

}

<?php

namespace App\Models;


use App\Events\GoodsDeleted;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\CacheManager;

class Goods extends BaseModel
{

    use SoftDeletes;

    protected $table = 'goods';

    protected $fillable = [
        'group_id', 'gd_name', 'gd_description', 'gd_keywords', 'picture', 'picture_url',
        'sales_volume', 'ord', 'payment_limit',
        'buy_limit_num', 'buy_min_num', 'buy_prompt', 'description', 'usage_instructions',
        'type', 'wholesale_price_cnf', 'wholesale_prices', 'other_ipu_cnf', 
        'customer_form_fields', 'api_hook', 'preselection', 'is_open', 'require_login'
    ];

    protected $casts = [
        'customer_form_fields' => 'array',
        'wholesale_prices' => 'array',
        'payment_limit' => 'array',
        'preselection' => 'decimal:2',
        'sales_volume' => 'integer',
        'ord' => 'integer',
        'buy_limit_num' => 'integer',
        'buy_min_num' => 'integer',
        'type' => 'integer',
        'api_hook' => 'integer',
        'is_open' => 'boolean',
        'require_login' => 'boolean',
    ];

    protected $dispatchesEvents = [
        'deleted' => GoodsDeleted::class
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::updated(function ($goods) {
            CacheManager::forgetGoodsWithSub($goods->id);
        });
        
        static::deleted(function ($goods) {
            CacheManager::forgetGoodsWithSub($goods->id);
        });
    }
    

    /**
     * 关联分组
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function group()
    {
        return $this->belongsTo(GoodsGroup::class, 'group_id');
    }

    /**
     * 关联优惠券
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function coupon()
    {
        return $this->belongsToMany(Coupon::class, 'coupons_goods', 'goods_id', 'coupons_id');
    }

    /**
     * 关联卡密（通过子规格）
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function carmis()
    {
        return $this->hasManyThrough(Carmis::class, GoodsSub::class, 'goods_id', 'sub_id');
    }

    /**
     * 获取商品类型映射
     *
     * @return array
     */
    public static function getGoodsTypeMap()
    {
        return [
            self::AUTOMATIC_DELIVERY => __('goods.fields.automatic_delivery'),
            self::MANUAL_PROCESSING => __('goods.fields.manual_processing'),
            self::AUTOMATIC_PROCESSING => __('goods.fields.automatic_processing'),
        ];
    }
    
    /**
     * 关联商品规格
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function goods_sub()
    {
        return $this->hasMany(GoodsSub::class);
    }
    
    /**
     * 关联文章
     */
    public function articles()
    {
        return $this->belongsToMany(Articles::class, 'article_goods', 'goods_id', 'article_id')
                    ->withTimestamps()
                    ->withPivot('sort')
                    ->orderBy('pivot_sort', 'desc');
    }
    
    /**
     * 批发价兼容：后台通过 wholesale_prices (JSON) 编辑，前台/定价通过 wholesale_price_cnf (字符串) 读取。
     * 若 wholesale_price_cnf 为空但 wholesale_prices 有数据，自动转换为旧格式字符串。
     */
    public function getWholesalePriceCnfAttribute($value)
    {
        if (!empty($value)) {
            return $value;
        }

        $prices = $this->wholesale_prices;
        if (empty($prices) || !is_array($prices)) {
            return $value;
        }

        $lines = [];
        foreach ($prices as $item) {
            if (isset($item['min_quantity']) && isset($item['unit_price'])) {
                $lines[] = $item['min_quantity'] . '=' . $item['unit_price'];
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取图片路径
     */
    public function getPictureAttribute($value)
    {
        if (!empty($this->attributes['picture_url'])) {
            return $this->attributes['picture_url'];
        }
        
        return $value;
    }
    
}

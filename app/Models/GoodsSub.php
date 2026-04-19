<?php

namespace App\Models;


use App\Events\GoodsDeleted;
use App\Services\CacheManager;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsSub extends BaseModel
{

    protected $table = 'goods_sub';

    protected $fillable = [
        'goods_id',
        'name',
        'price',
        'stock',
        'sales_volume'
    ];

    protected static function boot()
    {
        parent::boot();

        static::updated(function (GoodsSub $sub) {
            CacheManager::forgetGoodsWithSub($sub->goods_id);
        });

        static::deleted(function (GoodsSub $sub) {
            CacheManager::forgetGoodsWithSub($sub->goods_id);
        });
    }

    public function goods()
    {
        return $this->belongsTo(Goods::class);
    }

    /**
     * 关联当前子规格的所有分组价
     */
    public function groupPrices()
    {
        return $this->hasMany(GoodsSubGroupPrice::class, 'sub_id');
    }

    /**
     * 根据用户的分组返回应付的子规格单价：
     * - 用户已登录且属于某分组且该子规格设置了分组价 → 返回分组绝对价
     * - 否则返回子规格默认 price
     *
     * @param  User|null  $user
     * @return float
     */
    public function getPriceForUser(?User $user = null): float
    {
        $defaultPrice = (float) $this->attributes['price'];

        if (! $user || ! $user->group_id) {
            return $defaultPrice;
        }

        $groupPrice = GoodsSubGroupPrice::where('sub_id', $this->id)
            ->where('group_id', $user->group_id)
            ->value('price');

        return $groupPrice !== null ? (float) $groupPrice : $defaultPrice;
    }

    /**
     * 自动发货自动计算库存
     *
     * @author    outtime<beprivacy@icloud.com>
     * @copyright outtime<beprivacy@icloud.com>
     * @link      https://outti.me
     */
     public function getStockAttribute()
     {

        if ($this->goods->type == self::AUTOMATIC_DELIVERY) {
            return Carmis::where('goods_id', $this->goods_id)
                ->where('sub_id', $this->id)
                ->where('status', Carmis::STATUS_UNSOLD)
                ->count();
        }
        return $this->attributes['stock'];
     }
}

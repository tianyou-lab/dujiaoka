<?php

namespace App\Models;

use App\Services\CacheManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsSubGroupPrice extends Model
{
    protected $table = 'goods_sub_group_prices';

    protected $fillable = [
        'sub_id',
        'group_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        $invalidateCache = function (GoodsSubGroupPrice $row) {
            $goodsId = optional(GoodsSub::find($row->sub_id))->goods_id;
            if ($goodsId) {
                CacheManager::forgetGoodsWithSub((int) $goodsId);
            }
        };

        static::saved($invalidateCache);
        static::deleted($invalidateCache);
    }

    public function sub(): BelongsTo
    {
        return $this->belongsTo(GoodsSub::class, 'sub_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'group_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends BaseModel
{

    use SoftDeletes;

    protected $table = 'coupons';

    protected $casts = [
        'type' => 'integer',
        'discount' => 'decimal:2',
        'limit' => 'integer',
        'ret' => 'integer',
        'status' => 'boolean',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    const TYPE_PERCENT = 1; //系数优惠
    const TYPE_FIXED = 2; //整体固定金额优惠
    const TYPE_EACH = 3; //每件固定金额优惠

    /**
     * 关联商品
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     *
     */
    public function goods()
    {
        return $this->belongsToMany(Goods::class, 'coupons_goods', 'coupons_id', 'goods_id');
    }


    public static function getTypeMap()
    {
        return [
            self::TYPE_PERCENT => __('coupon.fields.type_percent'),
            self::TYPE_FIXED => __('coupon.fields.type_fixed'),
            self::TYPE_EACH => __('coupon.fields.type_each'),
        ];
    }


}

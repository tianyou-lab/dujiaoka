<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'goods_id',
        'sub_id',
        'goods_name',
        'unit_price',
        'quantity',
        'subtotal',
        'info',
        'type'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
        'type' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function goods(): BelongsTo
    {
        // withTrashed 确保软删除商品在履约阶段仍可读取，防止 goods=null 跳过发货
        return $this->belongsTo(Goods::class)->withTrashed();
    }

    public function goodsSub(): BelongsTo
    {
        return $this->belongsTo(GoodsSub::class, 'sub_id');
    }
}
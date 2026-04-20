<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 嵌入式 V免签 内部订单模型
 *
 * 对应表 `vmq_pay_orders`，记录每一笔通过 dujiaoka 发起的 V免签 扫码支付。
 * 与 dujiaoka 的 `orders` 表一一对应（通过 order_sn）。
 */
class VmqPayOrder extends Model
{
    protected $table = 'vmq_pay_orders';

    public $timestamps = true;

    protected $fillable = [
        'order_sn',
        'vmq_order_id',
        'pay_id',
        'type',
        'price',
        'really_price',
        'pay_url',
        'is_auto',
        'state',
        'create_date',
        'pay_date',
        'close_date',
        'param',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'really_price' => 'decimal:2',
        'type'         => 'integer',
        'state'        => 'integer',
        'is_auto'      => 'integer',
        'create_date'  => 'integer',
        'pay_date'     => 'integer',
        'close_date'   => 'integer',
    ];

    public const TYPE_WECHAT = 1;
    public const TYPE_ALIPAY = 2;

    public const STATE_WAIT   = 0;
    public const STATE_PAID   = 1;
    public const STATE_CLOSED = -1;

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_WECHAT => '微信',
            self::TYPE_ALIPAY => '支付宝',
        ];
    }

    public static function getStateMap(): array
    {
        return [
            self::STATE_WAIT   => '待支付',
            self::STATE_PAID   => '已支付',
            self::STATE_CLOSED => '已关闭',
        ];
    }
}

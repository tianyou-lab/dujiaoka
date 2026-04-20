<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 嵌入式 V免签 金额占用锁
 *
 * 同一金额+同一支付类型，同一时刻仅允许一个待支付订单。
 * 依赖 MySQL 的 UNIQUE(price_key) 约束实现分布式互斥，
 * INSERT IGNORE 成功即抢到锁，失败则代表该金额被占用，应做金额偏移再重试。
 */
class VmqTmpPrice extends Model
{
    protected $table = 'vmq_tmp_prices';

    public $timestamps = false;

    protected $fillable = [
        'price_key',
        'vmq_order_id',
        'create_date',
    ];
}

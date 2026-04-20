<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 嵌入式 V免签 固定金额收款码
 *
 * 允许管理员给指定金额+支付类型预置专用二维码内容，
 * 比如某些收款场景 App 扫不到的固定金额可以走静态码回退。
 * 当 vmq_qrcodes 命中时，is_auto=0；否则 is_auto=1 由系统自动生成金额错位二维码。
 */
class VmqQrcode extends Model
{
    protected $table = 'vmq_qrcodes';

    public $timestamps = true;

    protected $fillable = [
        'type',
        'price',
        'pay_url',
        'image_path',
        'enable',
        'remark',
    ];

    protected $casts = [
        'type'   => 'integer',
        'price'  => 'decimal:2',
        'enable' => 'boolean',
    ];

    public const TYPE_WECHAT = 1;
    public const TYPE_ALIPAY = 2;

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_WECHAT => '微信',
            self::TYPE_ALIPAY => '支付宝',
        ];
    }
}

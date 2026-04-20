<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 嵌入式 V免签 全局设置键值对
 *
 * 常用 key：
 *   - key             通讯密钥（32 位随机串，与 App 端一致）
 *   - close_minutes   订单超时关闭（分钟）
 *   - pay_qf          金额错位方向：1=递增(+1分) 2=递减(-1分)
 *   - heart_timeout   App 心跳超时（秒）
 *   - last_heart      App 最后心跳时间（unix 秒）
 *   - last_pay        App 最后推送到账时间（unix 秒）
 *   - jk_state        监控 App 在线状态：1=在线 0=离线
 *   - enable          V免签 全局开关：1=启用 0=停用
 */
class VmqSetting extends Model
{
    protected $table = 'vmq_settings';

    public $timestamps = true;

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    protected $fillable = [
        'setting_key',
        'setting_val',
    ];

    public static function get(string $key, $default = null)
    {
        $row = self::where('setting_key', $key)->first();
        return $row ? $row->setting_val : $default;
    }

    public static function put(string $key, $value): void
    {
        self::updateOrCreate(
            ['setting_key' => $key],
            ['setting_val' => (string) $value]
        );
    }
}

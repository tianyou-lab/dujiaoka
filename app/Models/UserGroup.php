<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserGroup extends BaseModel
{
    use SoftDeletes;

    protected $table = 'user_groups';

    protected $fillable = [
        'name',
        'description',
        'color',
        'sort',
        'status',
    ];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
    ];

    const STATUS_ACTIVE = 1;
    const STATUS_DISABLED = 0;

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_ACTIVE => '启用',
            self::STATUS_DISABLED => '禁用',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'group_id');
    }

    public function getStatusTextAttribute(): string
    {
        return self::getStatusMap()[$this->status] ?? '未知';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public static function getActiveGroups()
    {
        return static::where('status', self::STATUS_ACTIVE)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}

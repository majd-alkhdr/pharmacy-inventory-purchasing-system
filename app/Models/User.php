<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',   // 🆕
        'is_active', // 🆕
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'           => 'hashed',
            'is_active'          => 'boolean', // 🆕
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * 🎯 نقطة فحص واحدة لكل قرار وصول بالتطبيق — الكونترولرات والواجهات
     * كلها تستدعي هذه الدالة بدل قراءة $user->role->can_view_xxx مباشرة
     * بأكثر من مكان. لو تغيّر شكل تخزين الصلاحيات مستقبلاً (جدول permissions
     * منفصل مثلاً)، هذا هو المكان الوحيد الذي يحتاج تعديلاً.
     *
     * @param string $moduleKey مثل 'transfers_operations'، 'purchasing_reports'
     */
    public function hasModuleAccess(string $moduleKey): bool
    {
        return (bool) ($this->role?->{"can_view_{$moduleKey}"} ?? false);
    }

    public function isAdmin(): bool
    {
        return $this->role?->key === Role::ADMIN;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    // 🎯 مفاتيح ثابتة — استخدمها بالكود بدل كتابة النص الخام 'admin' في
    // أكثر من مكان (لو تغيّر المفتاح يوماً، تعديل واحد هنا يكفي).
    public const ADMIN      = 'admin';
    public const TRANSFERS  = 'transfers';
    public const PURCHASING = 'purchasing';
    public const REPORTS    = 'reports';

    protected $fillable = [
        'key', 'name',
        'can_view_transfers_operations', 'can_view_transfers_reports',
        'can_view_purchasing_operations', 'can_view_purchasing_reports',
    ];

    protected function casts(): array
    {
        return [
            'can_view_transfers_operations'  => 'boolean',
            'can_view_transfers_reports'     => 'boolean',
            'can_view_purchasing_operations' => 'boolean',
            'can_view_purchasing_reports'    => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

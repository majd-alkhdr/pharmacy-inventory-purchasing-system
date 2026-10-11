<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'key' => Role::ADMIN, 'name' => 'مدير النظام',
                'can_view_transfers_operations' => true, 'can_view_transfers_reports' => true,
                'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true,
            ],
            [
                'key' => Role::TRANSFERS, 'name' => 'مستخدم المناقلات',
                'can_view_transfers_operations' => true, 'can_view_transfers_reports' => true,
                'can_view_purchasing_operations' => false, 'can_view_purchasing_reports' => false,
            ],
            [
                'key' => Role::PURCHASING, 'name' => 'مستخدم المشتريات',
                'can_view_transfers_operations' => false, 'can_view_transfers_reports' => false,
                'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true,
            ],
            [
                // 🎯 "قسم التقارير كامل" — كلا القسمين، بلا أي وصول لعمليات أي منهما
                'key' => Role::REPORTS, 'name' => 'مستخدم التقارير',
                'can_view_transfers_operations' => false, 'can_view_transfers_reports' => true,
                'can_view_purchasing_operations' => false, 'can_view_purchasing_reports' => true,
            ],
        ];

        foreach ($roles as $role) {
            // updateOrCreate بدل create — تشغيل الـ Seeder مرة ثانية لاحقاً
            // (بعد تعديل صلاحية دور مثلاً) يُحدِّث الموجود، لا يفشل بخطأ تكرار.
            Role::updateOrCreate(['key' => $role['key']], $role);
        }

        $this->command?->info('✅ تم زرع الأدوار الأربعة بنجاح.');
    }
}

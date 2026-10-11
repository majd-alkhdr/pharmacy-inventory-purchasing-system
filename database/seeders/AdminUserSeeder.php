<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 🎯 نقطة الإنشاء الوحيدة لأول حساب أدمن — لا توجد شاشة تسجيل بالتطبيق
 * إطلاقاً (بقرار صريح)، فهذا الـ Seeder هو الطريقة الوحيدة لكسر حلقة
 * "لا يوجد أدمن بعد لإنشاء أول مستخدم عبر شاشة إدارة المستخدمين".
 *
 * ⚠️ كلمة المرور تُولَّد عشوائياً وتُطبَع بمخرجات الطرفية **مرة واحدة
 * فقط** وقت التشغيل — لا تُحفَظ بأي مكان آخر، ولا تظهر مجدَّداً لاحقاً.
 * غيّرها فوراً بعد أول تسجيل دخول عبر شاشة "إدارة المستخدمين" (تعديل
 * الحساب الخاص بك → إعادة تعيين كلمة المرور).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('key', Role::ADMIN)->first();

        if (!$adminRole) {
            $this->command?->error('❌ لم يُعثَر على دور "admin" — شغِّل RoleSeeder أولاً قبل هذا الـ Seeder.');
            return;
        }

        $email = 'admin@pharmacy.local';

        if (User::where('email', $email)->exists()) {
            $this->command?->warn("⚠️ يوجد حساب بالفعل بالبريد {$email} — لن يُعاد إنشاؤه أو تغيير كلمة مروره. احذفه يدوياً أولاً لو أردت إعادة التشغيل من الصفر.");
            return;
        }

        $password = 'Admin@' . random_int(10000, 99999);

        User::create([
            'name'      => 'مدير النظام',
            'email'     => $email,
            'password'  => $password, // 🎯 الـ cast hashed بموديل User يُشفِّرها تلقائياً عند الحفظ
            'role_id'   => $adminRole->id,
            'is_active' => true,
        ]);

        $this->command?->info('════════════════════════════════════════');
        $this->command?->info('✅ تم إنشاء حساب المدير الأول بنجاح:');
        $this->command?->info("   البريد الإلكتروني: {$email}");
        $this->command?->info("   كلمة المرور المؤقتة: {$password}");
        $this->command?->warn('   ⚠️ غيّر كلمة المرور فوراً بعد أول تسجيل دخول — لن تُعرَض هذه القيمة مرة أخرى.');
        $this->command?->info('════════════════════════════════════════');
    }
}

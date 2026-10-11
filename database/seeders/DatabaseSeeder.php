<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 🩹 الترتيب إلزامي: الأدوار يجب أن تُزرَع قبل حساب المدير — هو
        // يعتمد على وجود دور 'admin' بالفعل ليربط نفسه به.
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}

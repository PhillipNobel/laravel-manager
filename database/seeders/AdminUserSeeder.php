<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('manager.local_admin_email');
        $password = config('manager.local_admin_password');

        if (! $email || ! $password) {
            throw new RuntimeException('Set LOCAL_ADMIN_EMAIL and LOCAL_ADMIN_PASSWORD before seeding the development administrator.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('manager.local_admin_name') ?: 'Laravel Manager Admin',
                'password' => Hash::make($password),
            ],
        );
    }
}

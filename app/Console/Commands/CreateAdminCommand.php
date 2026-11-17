<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Tạo tài khoản quản trị hệ thống ban đầu';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Tên người quản trị'));
        $username = trim((string) $this->ask('Tên đăng nhập'));

        if ($name === '' || $username === '') {
            $this->error('Tên người quản trị và tên đăng nhập không được để trống.');

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->error('Tên đăng nhập đã tồn tại.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Mật khẩu (ít nhất 12 ký tự)');
        $confirmation = (string) $this->secret('Nhập lại mật khẩu');
        if (mb_strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->error('Mật khẩu phải có ít nhất 12 ký tự và hai lần nhập phải khớp.');

            return self::FAILURE;
        }

        Artisan::call('db:seed', [
            '--class' => \Database\Seeders\RolePermissionSeeder::class,
            '--force' => true,
        ]);

        $role = Role::query()
            ->where('name', Role::SYSTEM_ADMIN)
            ->where('guard_name', 'web')
            ->firstOrFail();
        $user = User::query()->create([
            'name' => $name,
            'username' => $username,
            'password' => $password,
        ]);
        $user->assignRole($role);

        $this->info('Đã tạo tài khoản quản trị hệ thống.');

        return self::SUCCESS;
    }
}

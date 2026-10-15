<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ProvinceSeeder::class,
            CommuneSeeder::class,
            EthnicitySeeder::class,
            RolePermissionSeeder::class,
        ]);

        $shouldSeedTestAccounts = app()->environment(['local', 'testing'])
            && is_string(env('PCGD_TEST_ACCOUNT_PASSWORD'))
            && filled(env('PCGD_TEST_ACCOUNT_PASSWORD'))
            && \App\Models\Household::query()->exists();

        if ($shouldSeedTestAccounts) {
            $this->call(AccessControlTestAccountsSeeder::class);
        }
    }
}
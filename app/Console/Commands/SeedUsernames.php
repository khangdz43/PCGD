<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedUsernames extends Command
{
    protected $signature = 'users:seed-usernames';
    protected $description = 'Set username from email prefix for users that have no username';

    public function handle(): void
    {
        $users = User::whereNull('username')->get();
        foreach ($users as $user) {
            $base = explode('@', $user->email)[0];
            $uname = $base;
            $i = 1;
            while (User::where('username', $uname)->where('id', '!=', $user->id)->exists()) {
                $uname = $base . $i;
                $i++;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $uname]);
            $this->info("User {$user->id} ({$user->name}): username = {$uname}");
        }
        $this->info('Done.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const USERNAME = 'aikopo';

    /**
     * Run the database seeds.
     *
     * Local only: the test account has a well-known password.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        User::create([
            'username' => self::USERNAME,
            'password' => 'aikopo'
        ]);
    }
}

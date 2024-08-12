<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ShopperSeeder extends Seeder
{
    public const COUNT = 200;

    public function run(): void
    {
        $password = Hash::make('password');
        $now = now();

        $rows = collect(range(1, self::COUNT))->map(fn (int $i) => [
            'name' => sprintf('Shopper %03d', $i),
            'email' => sprintf('shopper%03d@flash-sale.test', $i),
            'password' => $password,
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->upsert($rows->all(), ['email'], ['name', 'updated_at']);
    }
}

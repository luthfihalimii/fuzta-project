<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Enums\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(UnitSeeder::class);

        $first = Branch::query()->firstOrCreate(
            ['code' => 'PF-01'],
            [
                'name' => 'Pizza Fruzta Pusat',
                'address' => 'Cabang pusat',
                'phone' => '081200000001',
                'is_active' => true,
            ],
        );

        $second = Branch::query()->firstOrCreate(
            ['code' => 'PF-02'],
            [
                'name' => 'Pizza Fruzta Cabang 2',
                'address' => 'Cabang kedua',
                'phone' => '081200000002',
                'is_active' => true,
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'owner@pizzafruzta.test'],
            [
                'name' => 'Pemilik',
                'password' => 'password',
                'role' => Role::Owner,
                'branch_id' => null,
                'is_active' => true,
            ],
        );

        foreach ([$first, $second] as $branch) {
            $suffix = strtolower($branch->code);

            User::query()->firstOrCreate(
                ['email' => "kasir.{$suffix}@pizzafruzta.test"],
                [
                    'name' => "Kasir {$branch->code}",
                    'password' => 'password',
                    'role' => Role::Cashier,
                    'branch_id' => $branch->id,
                    'is_active' => true,
                ],
            );

            User::query()->firstOrCreate(
                ['email' => "dapur.{$suffix}@pizzafruzta.test"],
                [
                    'name' => "Dapur {$branch->code}",
                    'password' => 'password',
                    'role' => Role::Kitchen,
                    'branch_id' => $branch->id,
                    'is_active' => true,
                ],
            );
        }
    }
}

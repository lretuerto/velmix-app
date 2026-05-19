<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            ['id' => 10, 'code' => 'botica-central', 'name' => 'Botica Central', 'status' => 'active'],
            ['id' => 20, 'code' => 'botica-sur', 'name' => 'Botica Sur', 'status' => 'active'],
        ];

        foreach ($tenants as $tenant) {
            DB::table('tenants')->updateOrInsert(
                ['id' => $tenant['id']],
                [
                    'code' => $tenant['code'],
                    'name' => $tenant['name'],
                    'status' => $tenant['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}

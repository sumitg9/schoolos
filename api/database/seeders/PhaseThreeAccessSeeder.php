<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseThreeAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'attendance.view' => 'View student attendance',
            'attendance.create' => 'Record student attendance',
            'fees.view' => 'View fee charges and balances',
            'fees.create' => 'Post fee charges',
            'fee_payments.view' => 'View payment history',
            'fee_payments.create' => 'Record fee payments',
            'portal_accounts.create' => 'Create portal activation codes',
        ];

        DB::transaction(function () use ($permissions) {
            $ids = [];
            foreach ($permissions as $name => $label) {
                $ids[$name] = Permission::query()->updateOrCreate(['name' => $name], ['label' => $label])->id;
            }

            foreach (['super-admin', 'school-admin'] as $roleName) {
                Role::query()->where('name', $roleName)->firstOrFail()->permissions()->syncWithoutDetaching(array_values($ids));
            }

            Role::query()->where('name', 'principal')->firstOrFail()->permissions()->syncWithoutDetaching(array_values($ids));
            Role::query()->where('name', 'teacher')->firstOrFail()->permissions()->syncWithoutDetaching([
                $ids['attendance.view'],
                $ids['attendance.create'],
            ]);
            Role::query()->where('name', 'accountant')->firstOrFail()->permissions()->syncWithoutDetaching([
                $ids['fees.view'],
                $ids['fees.create'],
                $ids['fee_payments.view'],
                $ids['fee_payments.create'],
            ]);
        });
    }
}

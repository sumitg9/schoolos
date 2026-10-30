<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);
        $this->call(PhaseThreeAccessSeeder::class);
        $school = School::query()->orderBy('id')->first();
        if (! $school) {
            $school = School::query()->create(['name' => 'School', 'slug' => 'default-school', 'status' => 'active']);
        }
        SchoolSetting::query()->firstOrCreate(
            ['school_id' => $school->id, 'singleton_key' => 'school'],
            ['name' => $school->name],
        );

        $email = env('INITIAL_ADMIN_EMAIL');
        $password = env('INITIAL_ADMIN_PASSWORD');

        if (is_string($email) && $email !== '' && is_string($password) && strlen($password) >= 12) {
            $admin = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => env('INITIAL_ADMIN_NAME', 'School Administrator'),
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'school_id' => $school->id,
                ],
            );
            $admin->roles()->syncWithoutDetaching([
                Role::query()->where('name', 'super-admin')->value('id'),
            ]);
        }

        if (filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}

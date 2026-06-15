<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(FoundationSeeder::class);

        if (! $this->shouldSeedDemoUsers()) {
            $this->command?->warn('Demo users were skipped. Create the first production admin with: php artisan app:create-admin');

            return;
        }

        $this->createUserWithRole('Ahmed Mansoor', 'admin@example.test', 'admin');
        $this->createUserWithRole('Sales User', 'sales@example.test', 'salesperson');
        $this->createUserWithRole('Follow-Up User', 'followup@example.test', 'follow-up');
    }

    private function shouldSeedDemoUsers(): bool
    {
        $default = ! app()->environment('production');

        return filter_var(env('SEED_DEMO_USERS', $default), FILTER_VALIDATE_BOOL);
    }

    private function createUserWithRole(string $name, string $email, string $roleSlug): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $role = Role::where('slug', $roleSlug)->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }
}

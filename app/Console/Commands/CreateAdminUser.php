<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Admin display name}
        {--email= : Admin email address}
        {--password= : Admin password}
        {--force : Update an existing user without prompting}';

    protected $description = 'Create or update the first production admin user.';

    public function handle(): int
    {
        $role = Role::query()->where('slug', 'admin')->first();

        if (! $role) {
            $this->error('The admin role does not exist. Run: php artisan db:seed --class=Database\\\\Seeders\\\\FoundationSeeder --force');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Admin name')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Admin email')));
        $password = (string) ($this->option('password') ?: $this->secret('Admin password'));

        if (! $this->option('password')) {
            $confirmation = (string) $this->secret('Confirm admin password');

            if ($password !== $confirmation) {
                $this->error('Password confirmation does not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:10'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user && ! $this->option('force')) {
            $confirmed = $this->confirm("A user with {$email} already exists. Update this user and attach the admin role?", false);

            if (! $confirmed) {
                $this->warn('No changes made.');

                return self::SUCCESS;
            }
        }

        $user ??= new User(['email' => $email]);
        $user->name = $name;
        $user->password = $password;
        $user->status = 'active';
        $user->forceFill(['email_verified_at' => now()]);
        $user->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Admin user is ready: {$email}");

        return self::SUCCESS;
    }
}

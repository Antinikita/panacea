<?php

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use Illuminate\Console\Command;

/**
 * One-off ops command: rotate the admin account password without
 * needing tinker (which is dev-only since the security hardening pass).
 *
 * Usage:
 *   php artisan admin:set-password <password>           (looks up admin@panacea.local)
 *   php artisan admin:set-password <password> --email=foo@bar.com
 *
 * Why a dedicated command and not env-var reseed: the seeder only
 * creates the admin row when missing; it doesn't update an existing
 * one. This command always overwrites. It refuses to run if no admin
 * row exists — use the seeder for that case so role/permission setup
 * runs alongside.
 */
class SetAdminPassword extends Command
{
    protected $signature = 'admin:set-password
        {password : The new password (will be hashed via the model cast)}
        {--email=admin@panacea.local : Admin email to target}';

    protected $description = 'Rotate the password of an existing admin user.';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $password = (string) $this->argument('password');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $user = User::byEmail($email);
        if (! $user) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        if (! $user->hasRole('admin')) {
            $this->warn("Target user {$email} does not currently have the admin role. Proceeding anyway.");
        }

        $user->password = $password; // 'hashed' cast handles the bcrypt/argon
        $user->save();

        $this->info("Password rotated for {$email} (user id #{$user->id}).");
        return self::SUCCESS;
    }
}

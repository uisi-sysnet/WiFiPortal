<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// php artisan wifi:make-admin admin@example.com --name="Network Admin"
Artisan::command('wifi:make-admin {email} {--name=Administrator}', function (string $email) {
    $password = $this->secret('Password (min 12 characters)');

    if (strlen((string) $password) < 12) {
        $this->error('Password must be at least 12 characters.');

        return 1;
    }

    User::updateOrCreate(
        ['email' => $email],
        ['name' => $this->option('name'), 'password' => $password] // hashed by the User model cast
    );

    $this->info("Admin {$email} is ready. Sign in at /login.");
})->purpose('Create or reset a dashboard administrator');

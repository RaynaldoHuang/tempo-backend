<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'padel:admin {email} {--name=Panitia Tempo}';

    protected $description = 'Create a tournament administrator with a random password';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('Email tidak valid atau sudah digunakan.');

            return self::FAILURE;
        }
        $password = Str::password(18);
        $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
        $user->role = 'admin';
        $user->save();
        $this->info("Email: {$email}");
        $this->info("Password: {$password}");

        return self::SUCCESS;
    }
}

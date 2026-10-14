<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateGharUser extends Command
{
    protected $signature = 'ghar:create-user';

    protected $description = 'Interactively provision a Ghar account without putting passwords in shell history';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 12 characters, including letters and numbers)');
        $confirmation = $this->secret('Confirm password');
        $v = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $this->info('Account created. The project founder can now add this account from Team.');

        return self::SUCCESS;
    }
}

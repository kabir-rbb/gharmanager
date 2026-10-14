<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class E2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite') {
            throw new RuntimeException('E2E accounts may only be seeded into a disposable local/testing SQLite database.');
        }
        foreach (['demo' => 'Demo Founder', 'professional' => 'Demo Professional', 'member' => 'Demo Member'] as $email => $name) {
            User::updateOrCreate(['email' => $email.'@ghar.test'], ['name' => $name, 'password' => 'LocalDemo!2026']);
        }
    }
}

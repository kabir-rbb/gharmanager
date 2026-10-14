<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateGharUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_provisioning_hashes_the_password(): void
    {
        $this->artisan('ghar:create-user')
            ->expectsQuestion('Name', 'Test Engineer')
            ->expectsQuestion('Email', 'engineer@example.test')
            ->expectsQuestion('Password (at least 12 characters, including letters and numbers)', 'LocalEngineer123!')
            ->expectsQuestion('Confirm password', 'LocalEngineer123!')
            ->assertSuccessful();
        $u = User::where('email', 'engineer@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('LocalEngineer123!', $u->password));
        $this->assertNotEquals('LocalEngineer123!', $u->password);
    }

    public function test_password_confirmation_is_required(): void
    {
        $this->artisan('ghar:create-user')
            ->expectsQuestion('Name', 'Test Engineer')
            ->expectsQuestion('Email', 'engineer@example.test')
            ->expectsQuestion('Password (at least 12 characters, including letters and numbers)', 'LocalEngineer123!')
            ->expectsQuestion('Confirm password', 'Different123!')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}

<?php

use App\Http\Controllers\GharController;
use App\Http\Controllers\ProjectOperationsController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

Route::post('/token', function (Request $r) {
    $v = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'required|string|max:80']);
    $u = User::where('email', $v['email'])->first();
    if (! $u || ! Hash::check($v['password'], $u->password)) {
        throw ValidationException::withMessages(['email' => 'The sign-in details are incorrect.']);
    }

    return ['token' => $u->createToken($v['device_name'], ['*'], now()->addDays(7))->plainTextToken];
})->middleware('throttle:login');
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', fn (Request $r) => $r->user());
    Route::delete('/token', function (Request $r) {
        $token = $r->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    });
    Route::get('/projects', [GharController::class, 'projects']);
    Route::post('/projects', [GharController::class, 'createProject']);
    Route::get('/projects/{project}', [GharController::class, 'detail']);
    Route::post('/projects/{project}/materials', [GharController::class, 'material']);
    Route::post('/projects/{project}/logs', [GharController::class, 'log']);
    Route::post('/projects/{project}/consultations', [GharController::class, 'consultation']);
    Route::get('/projects/{project}/members', [ProjectOperationsController::class, 'members']);
    Route::post('/projects/{project}/members', [ProjectOperationsController::class, 'saveMember']);
    Route::delete('/projects/{project}/members/{user}', [ProjectOperationsController::class, 'removeMember'])->whereNumber('user');
    Route::post('/projects/{project}/workers', [ProjectOperationsController::class, 'worker']);
    Route::post('/projects/{project}/attendance', [ProjectOperationsController::class, 'attendance']);
    Route::post('/projects/{project}/bills', [ProjectOperationsController::class, 'bill']);
    Route::post('/projects/{project}/payments', [ProjectOperationsController::class, 'payment']);
    Route::get('/projects/{project}/audit', [ProjectOperationsController::class, 'audit']);
});

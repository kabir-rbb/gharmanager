<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Controllers\GharController;
use Illuminate\Validation\ValidationException;
Route::post('/token',function(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string','device_name'=>'required|string|max:80']);$u=User::where('email',$v['email'])->first();if(!$u||!Hash::check($v['password'],$u->password))throw ValidationException::withMessages(['email'=>'The sign-in details are incorrect.']);return ['token'=>$u->createToken($v['device_name'],['*'],now()->addDays(7))->plainTextToken];})->middleware('throttle:login');
Route::middleware(['auth:sanctum','throttle:api'])->group(function(){
 Route::get('/me',fn(Request $r)=>$r->user());
 Route::delete('/token',function(Request $r){$token=$r->user()->currentAccessToken();if($token instanceof \Laravel\Sanctum\PersonalAccessToken)$token->delete();return response()->noContent();});
 Route::get('/projects',[GharController::class,'projects']);
 Route::post('/projects',[GharController::class,'createProject']);
 Route::get('/projects/{project}',[GharController::class,'detail']);
 Route::post('/projects/{project}/materials',[GharController::class,'material']);
 Route::post('/projects/{project}/logs',[GharController::class,'log']);
 Route::post('/projects/{project}/consultations',[GharController::class,'consultation']);
});

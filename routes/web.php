<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
Route::get('/',fn()=>view('app'));
Route::post('/login',function(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string']);if(!Auth::attempt($v))throw ValidationException::withMessages(['email'=>'The sign-in details are incorrect.']);$r->session()->regenerate();return ['user'=>$r->user()];})->middleware('throttle:login');
Route::post('/logout',function(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return response()->noContent();})->middleware('auth');

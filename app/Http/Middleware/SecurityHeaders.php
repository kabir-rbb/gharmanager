<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SecurityHeaders {public function handle(Request $r,Closure $next){$response=$next($r);$response->headers->set('X-Content-Type-Options','nosniff');$response->headers->set('X-Frame-Options','DENY');$response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');$response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');$response->headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");if($r->is('api/*'))$response->headers->set('Cache-Control','no-store');return $response;}}

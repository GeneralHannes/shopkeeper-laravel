<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// If WEB_TOKEN is set, require it (as ?token= or X-Token header) on every /api call
// except /api/auth. Mirrors require_token() in the FastAPI original.
class TokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/auth')) return $next($request);
        $token = trim((string) env('WEB_TOKEN', ''));
        if ($token !== '') {
            $supplied = trim((string) ($request->header('X-Token') ?? $request->query('token') ?? ''));
            if ($supplied !== $token) {
                return response()->json(['detail' => 'missing or wrong password'], 401);
            }
        }
        return $next($request);
    }
}

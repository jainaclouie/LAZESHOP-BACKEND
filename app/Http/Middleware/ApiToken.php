<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $user = $token ? User::where('api_token', hash('sha256', $token))->first() : null;
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        // Strict role hierarchy check
        if (!$user || $user->user_type !== 'superadmin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Access Denied. Highly restricted area. Requires Superadmin privileges.'
            ], 403);
        }

        return $next($request);
    }
}
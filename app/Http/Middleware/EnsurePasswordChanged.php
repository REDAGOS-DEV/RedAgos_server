<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * The only endpoints open to an account still on a temporary password:
     * loading the account, setting its own password, and signing out.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const ALLOWED = [
        ['GET', 'api/user'],
        ['POST', 'api/donors/password'],
        ['POST', 'api/logout'],
        ['POST', 'api/logout-all'],
    ];

    /**
     * Hold an account signed in with the counter's temporary password to
     * setting its own before anything else.
     *
     * On the whole api group rather than per route, so there is no route that
     * forgets it. The token is read through the sanctum guard because this runs
     * before a route's own auth:sanctum; a guest passes untouched and is that
     * middleware's to refuse.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user?->password_change_required || $this->allowed($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Please set your own password before continuing.',
            'code' => 'password_change_required',
        ], 403);
    }

    private function allowed(Request $request): bool
    {
        foreach (self::ALLOWED as [$method, $path]) {
            if ($request->isMethod($method) && $request->path() === $path) {
                return true;
            }
        }

        return false;
    }
}

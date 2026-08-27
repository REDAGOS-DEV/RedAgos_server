<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBloodCenterOperational
{
    /**
     * Refuse callers whose facility is not cleared to act on real data.
     *
     * Runs after role:blood_center, because holding the role is necessary but
     * not sufficient. A suspended facility's staff keep their role, so the role
     * middleware alone would wave them straight through, and an account may
     * authenticate before verifying its email address.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->refuse(
                'You are not authorized to access this resource.',
                'unauthenticated'
            );
        }

        // Checked first because it is the only one of these the caller can fix
        // themselves.
        if (! $user->hasVerifiedEmail()) {
            return $this->refuse(
                'Please verify your email address before using this facility.',
                'email_unverified'
            );
        }

        $user->loadMissing('facility');

        // Covers both a null facility_id and a facility that has since been
        // soft-deleted, since the relation returns null for a trashed row.
        // Failing closed is deliberate: without a facility there is no
        // isolation boundary to scope anything to.
        if (! $user->facility) {
            return $this->refuse(
                'This account is not linked to a facility.',
                'facility_missing'
            );
        }

        $status = $user->facility->status;

        if (! $status->canOperate()) {
            return $this->refuse($status->blockedMessage(), $status->blockedCode());
        }

        return $next($request);
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(string $message, string $code): Response
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], 403);
    }
}

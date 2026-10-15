<?php

namespace App\Http\Controllers;

use App\Service\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * An admin's own account (2026-10-15): changing their password.
 *
 * The same rules as the blood center's and donor's own password change: the
 * current password first, and every session ends afterwards so an older
 * token cannot outlive it.
 */
class AdminAccountController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function updatePassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // The hashed cast on User handles hashing.
        $user->password = $payload['password'];
        $user->save();

        // A password change must not leave older tokens usable.
        $user->tokens()->delete();

        $this->auditLogger->record($user, 'admin.password_changed', $user);

        return response()->json([
            'message' => 'Password updated successfully. Please sign in again.',
        ]);
    }
}

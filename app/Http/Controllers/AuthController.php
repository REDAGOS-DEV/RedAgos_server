<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Service\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Authenticate a user and issue a personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json(
            $this->authService->login($request->validated())
        );
    }

    /**
     * Revoke the access token used for the current request.
     */
    public function logout(Request $request): Response
    {
        $this->authService->logout($request->user());

        return response()->noContent();
    }

    /**
     * Revoke every access token belonging to the authenticated user.
     */
    public function logoutFromAllDevices(Request $request): Response
    {
        $this->authService->logoutFromAllDevices($request->user());

        return response()->noContent();
    }
}

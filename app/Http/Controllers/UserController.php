<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Service\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(ListUsersRequest $request): JsonResponse
    {
        return response()->json(
            $this->userService->listUser($request->integer('per_page', 15))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return response()->json($this->userService->createUser($request->validated()), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->userService->getUser($uuid));
    }

    public function update(UpdateUserRequest $request, string $uuid): JsonResponse
    {
        return response()->json($this->userService->updateUser($uuid, $request->validated()));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->userService->deleteUser($uuid);

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid): JsonResponse
    {
        return response()->json($this->userService->restoreUser($uuid));
    }
}

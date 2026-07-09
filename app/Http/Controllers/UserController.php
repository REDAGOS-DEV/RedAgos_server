<?php

namespace App\Http\Controllers;

use App\Service\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        return $this->userService->listUser($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->userService->createUser($request->all());
    }

    public function show(string $uuid)
    {
        return $this->userService->getUser($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->userService->updateUser($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->userService->deleteUser($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->userService->restoreUser($uuid);
    }
}
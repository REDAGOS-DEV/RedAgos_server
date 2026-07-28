<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DonorDashboardController;
use App\Http\Controllers\DonorProfileController;
use App\Http\Controllers\DonorRegistrationController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/donors/register', [DonorRegistrationController::class, 'register'])
    ->middleware('throttle:5,1');
Route::get('/donors/dashboard', [DonorDashboardController::class, 'show'])
    ->middleware('auth:sanctum');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/donors/profile', [DonorProfileController::class, 'show']);
    Route::patch('/donors/profile', [DonorProfileController::class, 'update']);
    Route::put('/donors/profile', [DonorProfileController::class, 'update']);
    Route::post('/donors/password', [DonorProfileController::class, 'updatePassword']);
    Route::patch('/donors/notification-preferences', [DonorProfileController::class, 'updateNotificationPreferences']);
});

Route::middleware(['auth:sanctum', 'role:admin', 'throttle:60,1'])->prefix('users')->group(function (): void {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{uuid}', [UserController::class, 'show'])->whereUuid('uuid');
    Route::put('/{uuid}', [UserController::class, 'update'])->whereUuid('uuid');
    Route::patch('/{uuid}', [UserController::class, 'update'])->whereUuid('uuid');
    Route::delete('/{uuid}', [UserController::class, 'destroy'])->whereUuid('uuid');
    Route::post('/{uuid}/restore', [UserController::class, 'restore'])->whereUuid('uuid');
});

Route::get('/user', function (Request $request) {
    return $request->user()?->load(['roles', 'donorProfile.bloodType']);
})->middleware('auth:sanctum');

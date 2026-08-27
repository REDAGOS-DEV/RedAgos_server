<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BloodCenterInventoryController;
use App\Http\Controllers\BloodCenterProfileController;
use App\Http\Controllers\BloodCenterReferenceController;
use App\Http\Controllers\BloodCenterRegistrationController;
use App\Http\Controllers\BloodCenterStaffController;
use App\Http\Controllers\BookingCatalogController;
use App\Http\Controllers\DonorAppointmentController;
use App\Http\Controllers\DonorDashboardController;
use App\Http\Controllers\DonorDonationController;
use App\Http\Controllers\DonorEligibilityController;
use App\Http\Controllers\DonorNotificationController;
use App\Http\Controllers\DonorProfileController;
use App\Http\Controllers\DonorRegistrationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\FacilityRegistrationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\UserController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/donors/register', [DonorRegistrationController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:3,10')
    ->name('password.email');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1')
    ->name('password.reset');

Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutFromAllDevices']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,10')
        ->name('verification.send');
});

Route::get('/donors/dashboard', [DonorDashboardController::class, 'show'])
    ->middleware('auth:sanctum');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/donors/profile', [DonorProfileController::class, 'show']);
    Route::patch('/donors/profile', [DonorProfileController::class, 'update']);
    Route::put('/donors/profile', [DonorProfileController::class, 'update']);
    Route::post('/donors/password', [DonorProfileController::class, 'updatePassword']);
    Route::patch('/donors/notification-preferences', [DonorProfileController::class, 'updateNotificationPreferences']);
});

Route::middleware(['auth:sanctum', 'role:donor'])->prefix('donors')->group(function (): void {
    Route::get('/eligibility/questions', [DonorEligibilityController::class, 'questions']);
    Route::get('/eligibility/prefill', [DonorEligibilityController::class, 'prefill']);
    Route::get('/eligibility', [DonorEligibilityController::class, 'status']);
    Route::post('/eligibility/screening', [DonorEligibilityController::class, 'submit'])
        ->middleware('throttle:5,60');

    Route::get('/qr-code', [DonorEligibilityController::class, 'qrCode']);
    Route::post('/qr-code/refresh', [DonorEligibilityController::class, 'refreshQrCode'])
        ->middleware('throttle:10,60');

    Route::get('/appointments', [DonorAppointmentController::class, 'index']);
    Route::post('/appointments', [DonorAppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}', [DonorAppointmentController::class, 'update'])
        ->whereNumber('appointment');
    Route::delete('/appointments/{appointment}', [DonorAppointmentController::class, 'destroy'])
        ->whereNumber('appointment');

    Route::get('/donations', [DonorDonationController::class, 'index']);

    Route::get('/notifications', [DonorNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [DonorNotificationController::class, 'unreadCount']);
    Route::post('/notifications/mark-all-read', [DonorNotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{notification}', [DonorNotificationController::class, 'update'])
        ->whereUuid('notification');

    Route::post('/avatar', [DonorProfileController::class, 'updateAvatar']);
    Route::delete('/account', [DonorProfileController::class, 'destroy']);
});

Route::get('/donors/{user}/avatar', [DonorProfileController::class, 'showAvatar'])
    ->middleware('signed')
    ->name('donors.avatar.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/blood-centers', [BookingCatalogController::class, 'bloodCenters']);
    Route::get('/blood-drives', [BookingCatalogController::class, 'bloodDrives']);
    Route::get('/time-slots', [BookingCatalogController::class, 'timeSlots']);
});

Route::get('/support/contact-info', fn () => response()->json(config('donation.support')));

// Blood Center — public registration. Issues no token and attaches no role.
Route::post('/blood-center/register', [BloodCenterRegistrationController::class, 'register'])
    ->middleware('throttle:5,1');

// Blood Center — applicant. No role held yet, so these are guarded by
// authentication alone; resubmission ownership is enforced in the service.
Route::middleware('auth:sanctum')->prefix('blood-center')->group(function (): void {
    Route::get('/registration-status', [BloodCenterRegistrationController::class, 'status']);
    Route::post('/registration/resubmit', [BloodCenterRegistrationController::class, 'resubmit'])
        ->middleware('throttle:5,60');
});

// Blood Center — role only. Deliberately outside the operational gate so a
// suspended or unverified user can still see why they are blocked and change
// their own password.
Route::middleware(['auth:sanctum', 'role:blood_center'])->prefix('blood-center')->group(function (): void {
    Route::get('/profile', [BloodCenterProfileController::class, 'show']);
    Route::patch('/profile', [BloodCenterProfileController::class, 'update']);
    Route::put('/profile', [BloodCenterProfileController::class, 'update']);
    Route::post('/password', [BloodCenterProfileController::class, 'updatePassword']);
});

// Blood Center — operational. Everything that touches real data attaches here:
// reference data, and the inventory endpoints Module 3 added.
//
// The group carries what every route shares; the department ability is per
// route, because they differ. `can:` takes one ability, so a pipe-separated
// list would be read as a single ability nobody holds.
Route::middleware(['auth:sanctum', 'role:blood_center', 'facility.operational'])
    ->prefix('blood-center')->group(function (): void {
        Route::get('/reference-data', [BloodCenterReferenceController::class, 'index'])
            ->middleware('can:reference.view');

        Route::get('/inventory', [BloodCenterInventoryController::class, 'index'])
            ->middleware('can:inventory.view');

        // Declared before /inventory/{unit}: the parameter is a string and
        // would otherwise swallow 'summary'.
        Route::get('/inventory/summary', [BloodCenterInventoryController::class, 'summary'])
            ->middleware('can:inventory.view');

        Route::post('/inventory', [BloodCenterInventoryController::class, 'store'])
            ->middleware('can:inventory.create');

        Route::patch('/inventory/{unit}', [BloodCenterInventoryController::class, 'update'])
            ->middleware('can:inventory.update')
            ->where('unit', '[A-Za-z0-9\-]+');

        Route::post('/inventory/{unit}/discard', [BloodCenterInventoryController::class, 'discard'])
            ->middleware('can:inventory.discard')
            ->where('unit', '[A-Za-z0-9\-]+');

        // Roster management, held by the supervisor alone. staff.manage says
        // the caller may manage staff; StaffService is what scopes every
        // lookup to their own facility.
        Route::middleware('can:staff.manage')->prefix('staff')->group(function (): void {
            Route::get('/', [BloodCenterStaffController::class, 'index']);
            Route::post('/', [BloodCenterStaffController::class, 'store']);
            Route::get('/{uuid}', [BloodCenterStaffController::class, 'show'])->whereUuid('uuid');
            Route::patch('/{uuid}', [BloodCenterStaffController::class, 'update'])->whereUuid('uuid');
            Route::put('/{uuid}', [BloodCenterStaffController::class, 'update'])->whereUuid('uuid');
            Route::delete('/{uuid}', [BloodCenterStaffController::class, 'destroy'])->whereUuid('uuid');
            Route::post('/{uuid}/restore', [BloodCenterStaffController::class, 'restore'])->whereUuid('uuid');
        });
    });

// Admin — facility registration review.
Route::middleware(['auth:sanctum', 'role:admin', 'throttle:60,1'])
    ->prefix('admin/facility-registrations')->group(function (): void {
        Route::get('/', [FacilityRegistrationController::class, 'index']);
        Route::post('/{facility}/approve', [FacilityRegistrationController::class, 'approve'])
            ->whereNumber('facility');
        Route::post('/{facility}/reject', [FacilityRegistrationController::class, 'reject'])
            ->whereNumber('facility');
        Route::post('/{facility}/suspend', [FacilityRegistrationController::class, 'suspend'])
            ->whereNumber('facility');
        Route::post('/{facility}/reinstate', [FacilityRegistrationController::class, 'reinstate'])
            ->whereNumber('facility');
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
    // facility is eager-loaded because the blood-centre client reads the
    // facility name and status straight off this payload; without it the
    // resource omits the key and the portal header renders blank.
    return UserResource::make(
        $request->user()->load(['roles', 'donorProfile.bloodType', 'facility'])
    );
})->middleware('auth:sanctum');

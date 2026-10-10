<?php

use App\Http\Controllers\AdminPrivilegeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BloodCenterBillingController;
use App\Http\Controllers\BloodCenterBillingTransactionController;
use App\Http\Controllers\BloodCenterCheckoutController;
use App\Http\Controllers\BloodCenterCollectionController;
use App\Http\Controllers\BloodCenterComponentController;
use App\Http\Controllers\BloodCenterCorrectionController;
use App\Http\Controllers\BloodCenterDonorController;
use App\Http\Controllers\BloodCenterDriveController;
use App\Http\Controllers\BloodCenterFacilityController;
use App\Http\Controllers\BloodCenterInventoryController;
use App\Http\Controllers\BloodCenterLaboratoryController;
use App\Http\Controllers\BloodCenterPosController;
use App\Http\Controllers\BloodCenterProfileController;
use App\Http\Controllers\BloodCenterReceiptController;
use App\Http\Controllers\BloodCenterReferenceController;
use App\Http\Controllers\BloodCenterReferralController;
use App\Http\Controllers\BloodCenterRequestController;
use App\Http\Controllers\BloodCenterStaffController;
use App\Http\Controllers\BloodCenterStatementController;
use App\Http\Controllers\BloodCenterWalkInController;
use App\Http\Controllers\BookingCatalogController;
use App\Http\Controllers\DonorAppointmentController;
use App\Http\Controllers\DonorDashboardController;
use App\Http\Controllers\DonorDonationController;
use App\Http\Controllers\DonorEligibilityController;
use App\Http\Controllers\DonorIdentityVerificationController;
use App\Http\Controllers\DonorNotificationController;
use App\Http\Controllers\DonorProfileController;
use App\Http\Controllers\DonorRegistrationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\FacilityApprovalController;
use App\Http\Controllers\FacilityManagementController;
use App\Http\Controllers\FacilityNotificationController;
use App\Http\Controllers\HospitalAvailabilityController;
use App\Http\Controllers\HospitalBillingController;
use App\Http\Controllers\HospitalBloodRequestController;
use App\Http\Controllers\HospitalDirectDistributionController;
use App\Http\Controllers\HospitalInventoryController;
use App\Http\Controllers\HospitalReferenceController;
use App\Http\Controllers\HospitalReplenishmentScheduleController;
use App\Http\Controllers\HospitalTransfusionRequestController;
use App\Http\Controllers\HospitalWeeklyRequestController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\StockThresholdController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\XenditWebhookController;
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

// `signed:relative`, not `signed`: an absolute signature also covers the scheme
// and host, and this endpoint is never reached on the host it was signed for —
// the SPA posts to its own origin and the dev proxy rewrites the Host, and a
// tunnel terminates TLS upstream so the request arrives as http. The path and
// query carry the claim; VerifyEmailNotification signs exactly those.
Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed:relative', 'throttle:6,1'])
    ->name('verification.verify');

// Public by necessity: login is refused until the address is verified, so
// somebody who never received the mail has no token to authenticate the
// resend below with. The reply never reveals whether the address exists.
Route::post('/email/resend-verification', [EmailVerificationController::class, 'resendForGuest'])
    ->middleware('throttle:3,10')
    ->name('verification.resend');

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

    // Throttled like the other write endpoints here: an identity document is a
    // file upload, and resubmission is meant to be occasional.
    Route::post('/identity', [DonorProfileController::class, 'submitIdentity'])
        ->middleware('throttle:6,1');

    Route::delete('/account', [DonorProfileController::class, 'destroy']);
});

// `signed:relative` for the same reason as /email/verify above: the <img> loads
// it through the SPA's own origin, never the host the link was signed on. The
// signature still covers the donor's uuid and the expiry.
Route::get('/donors/{user}/avatar', [DonorProfileController::class, 'showAvatar'])
    ->middleware('signed:relative')
    ->name('donors.avatar.show');

// Signed like the avatar above, so an <img> can load it without a bearer
// token. A logo is the facility's public face on its printed reports, not
// personal data, so an expiring link is enough.
Route::get('/blood-center/facility/{facility}/logo', [BloodCenterFacilityController::class, 'showLogo'])
    ->middleware('signed')
    ->whereNumber('facility')
    ->name('blood-center.facility.logo');

// Authenticated rather than signed, unlike the avatar above: a link that opens a
// government ID without credentials would be forwardable, and would leave nobody
// to record in the audit trail. DonorProfilePolicy decides who may look.
Route::get('/donors/{uuid}/identity-image', [DonorProfileController::class, 'showIdentityImage'])
    ->middleware(['auth:sanctum', 'throttle:30,1'])
    ->whereUuid('uuid')
    ->name('donors.identity-image.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/blood-centers', [BookingCatalogController::class, 'bloodCenters']);
    Route::get('/blood-drives', [BookingCatalogController::class, 'bloodDrives']);
    Route::get('/time-slots', [BookingCatalogController::class, 'timeSlots']);
});

Route::get('/support/contact-info', fn () => response()->json(config('donation.support')));

// Xendit's webhooks. Public by necessity — Xendit is the caller — and admitted
// only with the account's callback token, compared in constant time. The body
// is stored and queued; nothing is recorded until the server has re-fetched
// the session from Xendit itself.
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'store'])
    ->middleware(['throttle:120,1', 'xendit.callback']);

// There is deliberately no public facility registration route here. Blood
// centres and hospital blood banks are created by a Super Admin through
// POST /api/admin/facilities and nowhere else, so an unauthenticated caller has
// no path to a facility account whatever payload they send.

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

        // Shelf life and price, held per facility. Every department reads these
        // through reference-data above, but only a supervisor sets them:
        // center.configure is a MANAGEMENT ability, so no department grants it.
        // They are facility-scoped rather than network-wide because one centre
        // must not be able to change another's expiry dates, or switch on the
        // payment-before-release gate for a facility that never set a price.
        Route::prefix('blood-components')->middleware('can:center.configure')->group(function (): void {
            Route::get('/', [BloodCenterComponentController::class, 'index']);
            Route::patch('/{component}', [BloodCenterComponentController::class, 'update'])
                ->whereNumber('component');
        });

        // The facility's own logo, printed on its reports. A supervisor's
        // setting like shelf life and price, so the same ability. The facility
        // is the caller's own, resolved from the token.
        Route::post('/facility/logo', [BloodCenterFacilityController::class, 'uploadLogo'])
            ->middleware(['can:center.configure', 'throttle:10,1']);

        Route::delete('/facility/logo', [BloodCenterFacilityController::class, 'removeLogo'])
            ->middleware('can:center.configure');

        Route::get('/inventory', [BloodCenterInventoryController::class, 'index'])
            ->middleware('can:inventory.view');

        // Declared before /inventory/{unit}: the parameter is a string and
        // would otherwise swallow 'summary'.
        Route::get('/inventory/summary', [BloodCenterInventoryController::class, 'summary'])
            ->middleware('can:inventory.view');

        // The minimum stock per blood type and component. Everyone who can see
        // the inventory can see how it stands against its minimums; only the
        // Inventory Control Officer and the Center Admin set them. Declared
        // before /inventory/{unit} for the same reason as the summary above.
        Route::get('/inventory/thresholds', [StockThresholdController::class, 'index'])
            ->middleware('can:inventory.view');

        Route::put('/inventory/thresholds', [StockThresholdController::class, 'update'])
            ->middleware('can:inventory.thresholds');

        // Also declared before /inventory/{unit}, for the same reason as the
        // line above: the unit parameter is a string, so this path would
        // otherwise be read as a unit id. Gated on inventory.create rather than
        // inventory.view because it is a worklist for the people who book stock
        // in, not a report.
        Route::get('/inventory/intake-queue', [BloodCenterInventoryController::class, 'intakeQueue'])
            ->middleware('can:inventory.create');

        // The Daily Blood Stock Inventory. Declared before /inventory/{unit}
        // for the same reason as the two above. Issuance prepares and signs
        // it, so it carries Issuance's own ability rather than the shared
        // inventory.view every laboratory department also holds.
        Route::get('/inventory/stock-report', [BloodCenterInventoryController::class, 'stockReport'])
            ->middleware('can:inventory.create');

        Route::get('/inventory/stock-report/pdf', [BloodCenterInventoryController::class, 'stockReportPdf'])
            ->middleware(['can:inventory.create', 'throttle:20,1']);

        Route::post('/inventory', [BloodCenterInventoryController::class, 'store'])
            ->middleware('can:inventory.create');

        // Quarantine release, on both clearance tokens. Per donation, because
        // the tokens are the donation's. Its own ability, held by the
        // Inventory Control Officer; the service re-checks the tokens for
        // everyone, supervisors included.
        Route::post('/inventory/quarantine/{donation}/release', [BloodCenterInventoryController::class, 'releaseQuarantine'])
            ->middleware('can:inventory.release_quarantine')
            ->whereNumber('donation');

        // The final (Phase 2) labels of a released donation, for printing or
        // reprinting. Labelling is Issuance's step, so Issuance's ability.
        Route::get('/inventory/donations/{donation}/labels', [BloodCenterInventoryController::class, 'labels'])
            ->middleware('can:inventory.create')
            ->whereNumber('donation');

        Route::patch('/inventory/{unit}', [BloodCenterInventoryController::class, 'update'])
            ->middleware('can:inventory.update')
            ->where('unit', '[A-Za-z0-9\-]+');

        Route::post('/inventory/{unit}/discard', [BloodCenterInventoryController::class, 'discard'])
            ->middleware('can:inventory.discard')
            ->where('unit', '[A-Za-z0-9\-]+');

        // Incoming blood requests. The abilities these carry have existed in
        // DepartmentPermissions since the RBAC foundation landed with nothing
        // consuming them; these are the routes they were declared for.
        //
        // Reading and deciding are separated: requests.view lists and reviews,
        // requests.approve decides, requests.release dispatches. Splitting them
        // means a centre can let a junior triage the queue without also letting
        // them send blood out of the building.
        Route::prefix('blood-requests')->group(function (): void {
            Route::get('/', [BloodCenterRequestController::class, 'index'])
                ->middleware('can:requests.view');

            // Declared before /{bloodRequest}, which is numeric-constrained and
            // would not match "summary" anyway, but the order documents intent.
            Route::get('/summary', [BloodCenterRequestController::class, 'summary'])
                ->middleware('can:requests.view');

            // Walk-in Patient Transfusion requests: a watcher who came to the
            // centre instead of the hospital blood bank. Issuance phones the
            // hospital and records the request only once it is confirmed.
            // The duplicate lookup is a POST so a patient's name never sits in
            // a URL or an access log.
            Route::get('/walk-in/reference', [BloodCenterWalkInController::class, 'reference'])
                ->middleware('can:requests.record');

            Route::post('/walk-in/duplicates', [BloodCenterWalkInController::class, 'duplicates'])
                ->middleware(['can:requests.record', 'throttle:60,1']);

            Route::post('/walk-in', [BloodCenterWalkInController::class, 'store'])
                ->middleware(['can:requests.record', 'throttle:30,1']);

            Route::get('/{bloodRequest}/history', [BloodCenterRequestController::class, 'history'])
                ->middleware('can:requests.view')
                ->whereNumber('bloodRequest');

            // Declared before /{bloodRequest}, which is numeric-constrained and
            // so would not swallow this, but keeping the specific route first
            // matches how /summary and /track are placed elsewhere.
            Route::get('/{bloodRequest}/form', [BloodCenterRequestController::class, 'form'])
                ->middleware('can:requests.view')
                ->whereNumber('bloodRequest');

            Route::get('/{bloodRequest}', [BloodCenterRequestController::class, 'show'])
                ->middleware('can:requests.view')
                ->whereNumber('bloodRequest');

            // Linking a bag to a request, and so to its patient: the
            // crossmatch technician's act, and Issuance's. Its own ability
            // since staff roles, so crossmatch can allocate without also being
            // able to reject a hospital's request.
            Route::post('/{bloodRequest}/allocate', [BloodCenterRequestController::class, 'allocate'])
                ->middleware('can:requests.process')
                ->whereNumber('bloodRequest');

            Route::post('/{bloodRequest}/reject', [BloodCenterRequestController::class, 'reject'])
                ->middleware('can:requests.approve')
                ->whereNumber('bloodRequest');

            // Giving a hold back is an approval-level decision, not a release:
            // nothing leaves the building, the units simply return to stock.
            Route::post('/{bloodRequest}/release-holds', [BloodCenterRequestController::class, 'releaseHolds'])
                ->middleware('can:requests.approve')
                ->whereNumber('bloodRequest');

            Route::post('/{bloodRequest}/release', [BloodCenterRequestController::class, 'release'])
                ->middleware('can:requests.release')
                ->whereNumber('bloodRequest');

            // Closing the rest of a line the centre cannot supply is a
            // decision on the hospital's request, like a rejection.
            Route::post('/{bloodRequest}/items/{item}/close', [BloodCenterRequestController::class, 'closeLine'])
                ->middleware('can:requests.approve')
                ->whereNumber('bloodRequest')
                ->whereNumber('item');
        });

        // Billing. Issuance holds billing.view read-only so release can check
        // whether anything is owed without being able to alter the answer;
        // recording money is the Billing department's alone.
        Route::prefix('billings')->group(function (): void {
            // Declared before /{bloodRequest}, which is numeric-constrained and
            // so would not swallow this, but the specific route stays first to
            // match how /summary and /track are placed elsewhere.
            Route::get('/', [BloodCenterBillingController::class, 'index'])
                ->middleware('can:billing.view');

            // Counted on the server from the journal and the statements, never
            // from the page a browser happens to hold. No references in it.
            Route::get('/summary', [BloodCenterBillingController::class, 'summary'])
                ->middleware('can:billing.view');

            Route::get('/{bloodRequest}', [BloodCenterBillingController::class, 'show'])
                ->middleware('can:billing.view')
                ->whereNumber('bloodRequest');

            // A weekly bill the hospital settled outside RedAgos, recorded with
            // its reference. No money moves; it is the Billing department's record.
            Route::post('/{bloodRequest}/settlement', [BloodCenterBillingController::class, 'storeSettlement'])
                ->middleware(['can:billing.record_payment', 'throttle:30,1'])
                ->whereNumber('bloodRequest');

            // The payments themselves, with their reference numbers. Not part
            // of the statement above: billing.view only needs to know whether
            // a release is cleared, and is held by roles with no business
            // reading a payment.
            Route::get('/{bloodRequest}/payments', [BloodCenterBillingController::class, 'payments'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest');

            Route::post('/{bloodRequest}/payments', [BloodCenterBillingController::class, 'storePayment'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest');

            // Waiving a charge settles a statement, so it sits behind the same
            // ability as taking money for one: both decide that nothing more
            // is owed, and both release blood.
            Route::post('/{bloodRequest}/subsidy', [BloodCenterBillingController::class, 'storeSubsidy'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest');

            // Statements of Account: frozen revisions of the statement. Reading
            // them is billing.view, like the statement; issuing one consumes a
            // document number, so it is the Billing department's.
            Route::get('/{bloodRequest}/statements', [BloodCenterStatementController::class, 'index'])
                ->middleware('can:billing.view')
                ->whereNumber('bloodRequest');

            Route::post('/{bloodRequest}/statements', [BloodCenterStatementController::class, 'store'])
                ->middleware(['can:billing.record_payment', 'throttle:30,1'])
                ->whereNumber('bloodRequest');

            // GCash checkouts billing staff open at the counter for a patient's
            // watcher. Taking money electronically is taking money, so the same
            // ability as recording it. Resolving a checkout flagged for review
            // is narrowed further to the Billing Supervisor in the service.
            Route::post('/{bloodRequest}/checkout', [BloodCenterCheckoutController::class, 'store'])
                ->middleware(['can:billing.record_payment', 'throttle:20,1'])
                ->whereNumber('bloodRequest');

            Route::get('/{bloodRequest}/attempts', [BloodCenterCheckoutController::class, 'index'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest');

            Route::post('/{bloodRequest}/attempts/{attempt}/supersede', [BloodCenterCheckoutController::class, 'supersede'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest')
                ->whereNumber('attempt');

            Route::post('/{bloodRequest}/attempts/{attempt}/reverify', [BloodCenterCheckoutController::class, 'reverify'])
                ->middleware(['can:billing.record_payment', 'throttle:20,1'])
                ->whereNumber('bloodRequest')
                ->whereNumber('attempt');

            Route::post('/{bloodRequest}/attempts/{attempt}/close', [BloodCenterCheckoutController::class, 'close'])
                ->middleware('can:billing.record_payment')
                ->whereNumber('bloodRequest')
                ->whereNumber('attempt');
        });

        Route::get('/statements/{revision}/pdf', [BloodCenterStatementController::class, 'pdf'])
            ->middleware(['can:billing.view', 'throttle:30,1'])
            ->whereNumber('revision');

        // A receipt names the payment reference, so it is read behind the
        // ability to record payments, like the payments list.
        Route::get('/receipts/{receipt}/pdf', [BloodCenterReceiptController::class, 'pdf'])
            ->middleware(['can:billing.record_payment', 'throttle:30,1'])
            ->whereNumber('receipt');

        // The same receipt as data, for the counter's 80mm print.
        Route::get('/receipts/{receipt}', [BloodCenterReceiptController::class, 'show'])
            ->middleware('can:billing.record_payment')
            ->whereNumber('receipt');

        // The billing journal. Its rows carry payment references, so it is
        // read behind the ability to record payments, like the payments list.
        Route::get('/billing-transactions', [BloodCenterBillingTransactionController::class, 'index'])
            ->middleware('can:billing.record_payment');

        Route::get('/billing-transactions/export', [BloodCenterBillingTransactionController::class, 'export'])
            ->middleware(['can:billing.record_payment', 'throttle:10,1']);

        // The billing counter: cash shifts and finding a patient's bill. Taking
        // money at the counter is billing.record_payment; overseeing every
        // cashier's shifts is narrowed to the Billing Supervisor in the service.
        Route::prefix('pos')->middleware('can:billing.record_payment')->group(function (): void {
            Route::get('/session', [BloodCenterPosController::class, 'current']);

            Route::get('/sessions', [BloodCenterPosController::class, 'index']);

            Route::post('/sessions', [BloodCenterPosController::class, 'open'])
                ->middleware('throttle:20,1');

            Route::get('/sessions/{session}', [BloodCenterPosController::class, 'show'])
                ->whereNumber('session');

            Route::post('/sessions/{session}/close', [BloodCenterPosController::class, 'close'])
                ->middleware('throttle:20,1')
                ->whereNumber('session');

            Route::get('/sessions/{session}/pdf', [BloodCenterPosController::class, 'pdf'])
                ->middleware('throttle:30,1')
                ->whereNumber('session');

            Route::get('/lookup', [BloodCenterPosController::class, 'lookup'])
                ->middleware('throttle:120,1');

            Route::get('/queue', [BloodCenterPosController::class, 'queue']);

            Route::get('/bills/{bloodRequest}', [BloodCenterPosController::class, 'bill'])
                ->whereNumber('bloodRequest');
        });

        Route::get('/notifications', [FacilityNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [FacilityNotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-all-read', [FacilityNotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{notification}', [FacilityNotificationController::class, 'update'])
            ->whereUuid('notification');

        // Collection — the counter. Donors are not owned by a facility,
        // so DonorDirectoryService is what decides whether a caller sees a full
        // record or the standardised cross-facility summary.
        Route::prefix('donors')->group(function (): void {
            // donors.view_contact, which Recruitment also holds: the service
            // returns a contact-only list to anyone without donors.view.
            Route::get('/', [BloodCenterDonorController::class, 'index'])
                ->middleware('can:donors.view_contact');

            // Declared before /{uuid}: 'lookup' would otherwise be read as one.
            // appointments.verify rather than donors.view, so the collection
            // chair can match the ID card in front of them; the service
            // narrows the answer to identity for anyone without donors.view.
            Route::get('/lookup', [BloodCenterDonorController::class, 'lookup'])
                ->middleware('can:appointments.verify');

            Route::post('/', [BloodCenterDonorController::class, 'store'])
                ->middleware('can:donors.manage');

            Route::get('/{uuid}', [BloodCenterDonorController::class, 'show'])
                ->middleware('can:donors.view')->whereUuid('uuid');

            // The donor's clinical record: deferral reasons and final results.
            Route::get('/{uuid}/history', [BloodCenterDonorController::class, 'history'])
                ->middleware('can:donors.view_clinical')->whereUuid('uuid');

            // Its own ability, not donors.view: reading a donor's declared
            // health answers is a different act from looking them up, and the
            // service additionally refuses a facility the donor has not
            // presented at.
            Route::get('/{uuid}/health-questionnaire', [BloodCenterDonorController::class, 'healthQuestionnaire'])
                ->middleware(['can:donors.view_questionnaire', 'throttle:60,1'])->whereUuid('uuid');
        });

        // Mobile drives. The donor-facing GET /blood-drives is a read-only view
        // over the same rows; this is the only place they are written.
        Route::prefix('drives')->group(function (): void {
            Route::get('/', [BloodCenterDriveController::class, 'index'])
                ->middleware('can:drives.view');

            Route::post('/', [BloodCenterDriveController::class, 'store'])
                ->middleware('can:drives.manage');
        });

        Route::get('/collection/queue', [BloodCenterCollectionController::class, 'queue'])
            ->middleware('can:appointments.view');

        Route::post('/collection/verify-qr', [BloodCenterCollectionController::class, 'verifyQr'])
            ->middleware(['can:appointments.verify', 'throttle:60,1']);

        Route::post('/appointments/{appointment}/check-in', [BloodCenterCollectionController::class, 'checkIn'])
            ->middleware('can:appointments.verify')->whereNumber('appointment');

        Route::post('/appointments/{appointment}/no-show', [BloodCenterCollectionController::class, 'noShow'])
            ->middleware('can:appointments.verify')->whereNumber('appointment');

        Route::prefix('donations')->group(function (): void {
            Route::get('/', [BloodCenterCollectionController::class, 'index'])
                ->middleware('can:donations.view');

            // One ability per part of the visit, by who performs it: the
            // receptionist opens the donation, the screening physician accepts
            // or defers, the chair collects. Closing a visit that cannot go
            // ahead is open to all three.
            Route::post('/', [BloodCenterCollectionController::class, 'store'])
                ->middleware('can:donations.register');

            Route::patch('/{donation}/status', [BloodCenterCollectionController::class, 'updateStatus'])
                ->middleware('can:donations.close')->whereNumber('donation');

            Route::post('/{donation}/screening', [BloodCenterCollectionController::class, 'recordScreening'])
                ->middleware('can:donations.screen')->whereNumber('donation');

            Route::post('/{donation}/collection', [BloodCenterCollectionController::class, 'recordCollection'])
                ->middleware('can:donations.collect')->whereNumber('donation');
        });

        // Testing and Processing — the only place `completed` can be written,
        // and `completed` is what blood-unit intake gates on. Both departments
        // read the same queue; Testing records immunohematology and serology
        // (and works the counselling referral list), Processing records
        // components and completes or rejects.
        Route::prefix('laboratory')->group(function (): void {
            Route::get('/queue', [BloodCenterLaboratoryController::class, 'index'])
                ->middleware('can:lab.view');

            Route::get('/donations/{donation}', [BloodCenterLaboratoryController::class, 'show'])
                ->middleware('can:lab.view')->whereNumber('donation');

            // Section II's two Testing tables, saved separately so each is
            // stamped with its own "Screened by", and recorded by two
            // departments: Immunohematology types the unit, TTI Testing runs
            // the serology panel. There is no route that records an overall
            // result directly: it is derived from these, so nothing can pass a
            // donation without all five markers.
            Route::post('/donations/{donation}/immunohematology', [BloodCenterLaboratoryController::class, 'recordImmunohematology'])
                ->middleware('can:lab.record_immunohematology')->whereNumber('donation');

            Route::post('/donations/{donation}/serology', [BloodCenterLaboratoryController::class, 'recordSerology'])
                ->middleware('can:lab.record_serology')->whereNumber('donation');

            // Its own ability rather than lab.record_serology: this is the one
            // list that names which marker each donor was reactive for, and
            // the technologists recording markers are blind to identity.
            Route::get('/referrals', [BloodCenterReferralController::class, 'index'])
                ->middleware(['can:lab.referrals', 'throttle:60,1']);

            Route::patch('/referrals/{referral}', [BloodCenterReferralController::class, 'update'])
                ->middleware('can:lab.referrals')->whereNumber('referral');

            Route::post('/donations/{donation}/components', [BloodCenterLaboratoryController::class, 'declareComponents'])
                ->middleware('can:lab.record_components')->whereNumber('donation');

            Route::patch('/donations/{donation}/status', [BloodCenterLaboratoryController::class, 'updateStatus'])
                ->middleware('can:lab.update_status')->whereNumber('donation');
        });

        // Correction requests. A saved record is changed only this way: its
        // writer asks, and the department's approver or the Center Admin
        // decides. CorrectionService checks the requester writes that record
        // and the decider is the right one; the gates only say who may reach.
        Route::get('/corrections', [BloodCenterCorrectionController::class, 'index'])
            ->middleware('can:corrections.request');

        Route::post('/donations/{donation}/corrections', [BloodCenterCorrectionController::class, 'store'])
            ->middleware(['can:corrections.request', 'throttle:30,1'])->whereNumber('donation');

        // The same, for the records Issuance and Billing keep. The subject is
        // fixed by the route, so a body cannot aim one at the wrong table;
        // CorrectionService decides which roles may file each.
        Route::post('/inventory/{unit}/corrections', [BloodCenterCorrectionController::class, 'storeForUnit'])
            ->middleware(['can:corrections.request', 'throttle:30,1'])->where('unit', '[A-Za-z0-9\-]+');

        Route::post('/allocations/{allocation}/corrections', [BloodCenterCorrectionController::class, 'storeForAllocation'])
            ->middleware(['can:corrections.request', 'throttle:30,1'])->whereNumber('allocation');

        Route::post('/payments/{payment}/corrections', [BloodCenterCorrectionController::class, 'storeForPayment'])
            ->middleware(['can:corrections.request', 'throttle:30,1'])->whereNumber('payment');

        // A void of a counter payment while its shift is open, decided like a
        // correction. Refunds are settled outside RedAgos.
        Route::post('/payments/{payment}/void', [BloodCenterCorrectionController::class, 'storeVoidForPayment'])
            ->middleware(['can:corrections.request', 'throttle:30,1'])->whereNumber('payment');

        Route::post('/corrections/{correction}/approve', [BloodCenterCorrectionController::class, 'approve'])
            ->middleware('can:corrections.approve')->whereNumber('correction');

        Route::post('/corrections/{correction}/reject', [BloodCenterCorrectionController::class, 'reject'])
            ->middleware('can:corrections.approve')->whereNumber('correction');

        // Roster management, held by the supervisor alone. staff.manage says
        // the caller may manage staff; StaffService is what scopes every
        // lookup to their own facility.
        Route::middleware('can:staff.manage')->prefix('staff')->group(function (): void {
            Route::get('/', [BloodCenterStaffController::class, 'index']);

            // Declared before /{uuid}, which is uuid-constrained and would not
            // match "roles" anyway, but the specific route stays first.
            Route::get('/roles', [BloodCenterStaffController::class, 'roles']);

            Route::post('/', [BloodCenterStaffController::class, 'store']);
            Route::get('/{uuid}', [BloodCenterStaffController::class, 'show'])->whereUuid('uuid');
            Route::patch('/{uuid}', [BloodCenterStaffController::class, 'update'])->whereUuid('uuid');
            Route::put('/{uuid}', [BloodCenterStaffController::class, 'update'])->whereUuid('uuid');
            Route::delete('/{uuid}', [BloodCenterStaffController::class, 'destroy'])->whereUuid('uuid');
            Route::post('/{uuid}/restore', [BloodCenterStaffController::class, 'restore'])->whereUuid('uuid');
        });
    });

// Admin — facility management. This is the only place a blood centre or a
// hospital blood bank is created, which is why the whole group sits behind
// role:admin rather than carrying a public entry point beside it.
// `can:` narrows what role:admin used to hand out wholesale. role:admin stays
// in front of it as the cheap check that keeps non-admins out of the group at
// all; the privilege decides which admins get which route.
Route::middleware(['auth:sanctum', 'role:admin', 'throttle:60,1'])
    ->prefix('admin/facilities')->group(function (): void {
        Route::get('/', [FacilityManagementController::class, 'index'])
            ->middleware('can:admin.facility.manage');
        Route::post('/', [FacilityManagementController::class, 'store'])
            ->middleware('can:admin.facility.manage');

        // Legacy: facilities left in pending_approval by the removed public
        // registration flow. Their records are preserved rather than deleted,
        // so the Super Admin needs these to clear them by hand. Nothing created
        // today ever lands in that state.
        //
        // Guarded by approve rather than manage: deciding on an application and
        // maintaining a facility record are separate grants, and an auditor who
        // may read the list must not be able to wave one through.
        Route::post('/{facility}/approve', [FacilityApprovalController::class, 'approve'])
            ->whereNumber('facility')->middleware('can:admin.facility.approve');
        Route::post('/{facility}/reject', [FacilityApprovalController::class, 'reject'])
            ->whereNumber('facility')->middleware('can:admin.facility.approve');
    });

// Named deliberately, unlike the routes around them: DonorIdentityDecisionRequest
// Hospital Blood Bank — the requester side of the blood request workflow.
//
// Guarded by role and the operational gate, with no `can:` ability. That is
// deliberate and worth explaining, because every blood-centre route above does
// carry one. Abilities exist here to separate the four departments *within* a
// blood centre, which is what docs/BLOOD-CENTER.md charters. A hospital blood
// bank has no departments — users.department is null for all of its staff — and
// every one of its accounts does the same job, so there is nothing for an
// ability to separate. Adding one would also mean resolving the facility's type
// inside User::abilities(), which App\Support\AdminPrivileges documents as
// forbidden: that method runs on every gate check and every serialised user,
// and must not touch a relation.
//
// facility.operational carries the rest of the weight. Despite the middleware
// class being named for the blood centre, its checks are role-agnostic —
// verified email, a linked facility, and that facility approved — which is
// exactly the gate a requester needs.
Route::middleware(['auth:sanctum', 'role:blood_bank', 'facility.operational'])
    ->prefix('hospital')->group(function (): void {
        Route::get('/reference-data', [HospitalReferenceController::class, 'index']);

        Route::get('/availability', [HospitalAvailabilityController::class, 'index']);
        Route::get('/facilities', [HospitalAvailabilityController::class, 'facilities']);

        // The blood bank's own stock: bags this hospital confirmed receipt of,
        // and the patient tags placed on them. Type, component and expiry are
        // read from the centre's blood_units row and never copied, so a tag can
        // never reset a date.
        Route::prefix('inventory')->group(function (): void {
            Route::get('/', [HospitalInventoryController::class, 'index']);

            // Declared before /{unit}: the bag number is a string and would
            // otherwise swallow these.
            Route::get('/summary', [HospitalInventoryController::class, 'summary']);
            Route::get('/tag-events', [HospitalInventoryController::class, 'tagEvents']);

            // The hospital's own minimum stock per blood type and component.
            // A blood bank has no departments or abilities, so any account
            // may read and set them. Declared before /{unit} like the two
            // above: the bag number is a string and would swallow it.
            Route::get('/thresholds', [StockThresholdController::class, 'index']);
            Route::put('/thresholds', [StockThresholdController::class, 'update']);

            Route::get('/{unit}', [HospitalInventoryController::class, 'show'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/tag', [HospitalInventoryController::class, 'tag'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/crossmatch', [HospitalInventoryController::class, 'crossmatch'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/transfuse', [HospitalInventoryController::class, 'transfuse'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/release', [HospitalInventoryController::class, 'release'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/return', [HospitalInventoryController::class, 'confirmReturn'])
                ->where('unit', '[A-Za-z0-9\-]+');
            Route::post('/{unit}/discard', [HospitalInventoryController::class, 'discard'])
                ->where('unit', '[A-Za-z0-9\-]+');
        });

        // A patient's need, split across the centres asked to supply it. Each
        // share is a facility allocation — a blood request below — which the
        // centre works exactly as it always has.
        Route::prefix('transfusion-requests')->group(function (): void {
            Route::get('/', [HospitalTransfusionRequestController::class, 'index']);
            Route::post('/', [HospitalTransfusionRequestController::class, 'store']);

            // Advisory: which centres hold matching stock, earliest expiry
            // first. A POST so it can carry the component lines.
            Route::post('/sourcing', [HospitalTransfusionRequestController::class, 'draftSourcing'])
                ->middleware('throttle:60,1');

            // The check before recording a second requirement for the same
            // patient — including one a blood centre recorded here after a
            // walk-in. A POST so the patient's name stays out of URLs and
            // access logs.
            Route::post('/patient-matches', [HospitalTransfusionRequestController::class, 'patientMatches'])
                ->middleware('throttle:60,1');

            // Either reference on the watcher's paperwork: the PTR, or the RQ
            // of any of its allocations. Declared before {transfusionRequest}.
            Route::get('/track/{reference}', [HospitalTransfusionRequestController::class, 'track'])
                ->where('reference', '[A-Za-z0-9\-]+');

            Route::get('/{transfusionRequest}', [HospitalTransfusionRequestController::class, 'show'])
                ->whereNumber('transfusionRequest');
            Route::get('/{transfusionRequest}/history', [HospitalTransfusionRequestController::class, 'history'])
                ->whereNumber('transfusionRequest');
            Route::get('/{transfusionRequest}/sourcing', [HospitalTransfusionRequestController::class, 'sourcing'])
                ->whereNumber('transfusionRequest')
                ->middleware('throttle:60,1');

            Route::post('/{transfusionRequest}/allocations', [HospitalTransfusionRequestController::class, 'addAllocations'])
                ->whereNumber('transfusionRequest');
            Route::post('/{transfusionRequest}/allocations/{allocation}/withdraw', [HospitalTransfusionRequestController::class, 'withdrawAllocation'])
                ->whereNumber('transfusionRequest')
                ->whereNumber('allocation');
            Route::post('/{transfusionRequest}/items/{item}/close', [HospitalTransfusionRequestController::class, 'closeLine'])
                ->whereNumber('transfusionRequest')
                ->whereNumber('item');
            Route::post('/{transfusionRequest}/cancel', [HospitalTransfusionRequestController::class, 'cancel'])
                ->whereNumber('transfusionRequest');
        });

        // Receiving: the blood bank's request days, the weekly request it
        // sends a centre on them, and deliveries from outside RedAgos typed in
        // bag by bag. A weekly request is one replenishment per blood type —
        // its receipt is confirmed on each, through confirm-receipt below.
        Route::get('/replenishment-schedules', [HospitalReplenishmentScheduleController::class, 'index']);
        Route::put('/replenishment-schedules/{targetFacility}', [HospitalReplenishmentScheduleController::class, 'update'])
            ->whereNumber('targetFacility');
        Route::delete('/replenishment-schedules/{targetFacility}', [HospitalReplenishmentScheduleController::class, 'destroy'])
            ->whereNumber('targetFacility');

        Route::prefix('weekly-requests')->group(function (): void {
            Route::get('/', [HospitalWeeklyRequestController::class, 'index']);
            Route::post('/', [HospitalWeeklyRequestController::class, 'store']);
            Route::get('/status', [HospitalWeeklyRequestController::class, 'status']);
            Route::get('/{weeklyRequest}', [HospitalWeeklyRequestController::class, 'show'])
                ->whereNumber('weeklyRequest');
        });

        Route::get('/direct-distributions', [HospitalDirectDistributionController::class, 'index']);
        Route::post('/direct-distributions', [HospitalDirectDistributionController::class, 'store']);
        Route::get('/external-blood-sources', [HospitalDirectDistributionController::class, 'sources']);
        Route::post('/external-blood-sources', [HospitalDirectDistributionController::class, 'storeSource']);

        // Replenishment orders, and every facility allocation's own page:
        // receipt, the DOH form and its history live on the allocation.
        Route::get('/blood-requests', [HospitalBloodRequestController::class, 'index']);

        // Declared before the {bloodRequest} route below, which would otherwise
        // swallow "track" and then fail to match it as an integer.
        Route::get('/blood-requests/track/{reference}', [HospitalBloodRequestController::class, 'track'])
            ->where('reference', '[A-Za-z0-9\-]+');

        Route::get('/blood-requests/{bloodRequest}/form', [HospitalBloodRequestController::class, 'form'])
            ->whereNumber('bloodRequest');

        Route::get('/blood-requests/{bloodRequest}', [HospitalBloodRequestController::class, 'show'])
            ->whereNumber('bloodRequest');
        Route::post('/blood-requests/{bloodRequest}/cancel', [HospitalBloodRequestController::class, 'cancel'])
            ->whereNumber('bloodRequest');

        Route::get('/blood-requests/{bloodRequest}/history', [HospitalBloodRequestController::class, 'history'])
            ->whereNumber('bloodRequest');

        Route::post('/blood-requests/{bloodRequest}/items/{item}/close', [HospitalBloodRequestController::class, 'closeLine'])
            ->whereNumber('bloodRequest')
            ->whereNumber('item');

        // Receipt is confirmed by the receiving facility and nobody else. The
        // centre that dispatched the units cannot assert on the hospital's
        // behalf that they arrived, which is why this sits on the requester
        // side of the API rather than beside the release endpoint.
        Route::post('/blood-requests/{bloodRequest}/confirm-receipt', [HospitalBloodRequestController::class, 'confirmReceipt'])
            ->whereNumber('bloodRequest');

        // What the hospital's requests were billed, read only. The patient or
        // watcher pays at the blood centre, and a weekly order is billed by
        // statement only, so nothing here takes money.
        Route::get('/blood-requests/{bloodRequest}/billing', [HospitalBillingController::class, 'show'])
            ->whereNumber('bloodRequest');
        Route::get('/statements/{revision}/pdf', [HospitalBillingController::class, 'statementPdf'])
            ->middleware('throttle:30,1')
            ->whereNumber('revision');
        Route::get('/receipts/{receipt}/pdf', [HospitalBillingController::class, 'receiptPdf'])
            ->middleware('throttle:30,1')
            ->whereNumber('receipt');

        Route::get('/notifications', [FacilityNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [FacilityNotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-all-read', [FacilityNotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{notification}', [FacilityNotificationController::class, 'update'])
            ->whereUuid('notification');
    });

// branches on routeIs() to require a reason on reject and refuse one on approve,
// and routeIs() returns false for an unnamed route.
Route::middleware(['auth:sanctum', 'role:admin', 'can:admin.donor_identity.verify', 'throttle:60,1'])
    ->prefix('admin/donor-identities')->group(function (): void {
        Route::get('/', [DonorIdentityVerificationController::class, 'index'])
            ->name('admin.donor-identities.index');
        Route::post('/{uuid}/approve', [DonorIdentityVerificationController::class, 'approve'])
            ->whereUuid('uuid')->name('admin.donor-identities.approve');
        Route::post('/{uuid}/reject', [DonorIdentityVerificationController::class, 'reject'])
            ->whereUuid('uuid')->name('admin.donor-identities.reject');
    });

// The catalogue behind the account form. Guarded by the same privilege as the
// form itself, so an admin who cannot create accounts cannot enumerate what
// privileges exist either.
Route::middleware(['auth:sanctum', 'role:admin', 'can:admin.accounts.manage', 'throttle:60,1'])
    ->get('admin/privileges', [AdminPrivilegeController::class, 'index']);

// Creating a user is how an admin account comes into existence, so this group
// is what admin.accounts.manage actually protects. A scoped admin reaching it
// without the privilege could otherwise mint itself an unrestricted account and
// make every other guard here decorative.
Route::middleware(['auth:sanctum', 'role:admin', 'can:admin.accounts.manage', 'throttle:60,1'])->prefix('users')->group(function (): void {
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

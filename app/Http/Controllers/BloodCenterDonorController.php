<?php

namespace App\Http\Controllers;

use App\Enums\DonorEmailTemplate;
use App\Http\Requests\ListDonorsRequest;
use App\Http\Requests\LookupDonorRequest;
use App\Http\Requests\SendDonorEmailRequest;
use App\Http\Requests\StoreDonorPhotoRequest;
use App\Http\Requests\StoreWalkInDonorRequest;
use App\Http\Requests\UpdateDonorRecordRequest;
use App\Service\DonorDirectoryService;
use App\Service\DonorExportService;
use App\Service\DonorOutreachService;
use App\Service\DonorQuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BloodCenterDonorController extends Controller
{
    public function __construct(
        private readonly DonorDirectoryService $donorDirectoryService,
        private readonly DonorQuestionnaireService $donorQuestionnaireService,
        private readonly DonorExportService $donorExportService,
        private readonly DonorOutreachService $donorOutreachService
    ) {}

    /**
     * List the donors this facility has dealt with.
     */
    public function index(ListDonorsRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->browse(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Find one donor by an identifier presented at the counter.
     */
    public function lookup(LookupDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->lookup(
                $request->user(),
                $request->validated('type'),
                $request->validated('value')
            )
        );
    }

    /**
     * Show one donor, in whichever shape this facility is entitled to see.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->show($request->user(), $uuid)
        );
    }

    /**
     * List this facility's donations for one donor.
     */
    public function history(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->history($request->user(), $uuid)
        );
    }

    /**
     * Register a donor who has presented at the counter.
     */
    public function store(StoreWalkInDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->registerWalkIn($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Correct a donor's record, and this centre's status and notes for them.
     */
    public function update(UpdateDonorRecordRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->update($request->user(), $uuid, $request->validated())
        );
    }

    /**
     * Set the photo of a donor this centre registered.
     */
    public function photo(StoreDonorPhotoRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->updatePhoto($request->user(), $uuid, $request->file('photo'))
        );
    }

    /**
     * Download the filtered donor list as a CSV that opens in Excel.
     */
    public function export(ListDonorsRequest $request): StreamedResponse
    {
        return $this->donorExportService->csv($request->user(), $request->safe()->except('per_page'));
    }

    /**
     * Count who an eligibility reminder would reach, before anyone confirms it.
     */
    public function remindersPreview(ListDonorsRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorOutreachService->previewReminders($request->user(), $request->safe()->except('per_page'))
        );
    }

    /**
     * Email every active, eligible donor on the filtered list.
     */
    public function sendReminders(ListDonorsRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorOutreachService->sendReminders($request->user(), $request->safe()->except('per_page'))
        );
    }

    /**
     * Send one donor the centre's thank-you or eligibility reminder.
     */
    public function email(SendDonorEmailRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorOutreachService->sendOne(
                $request->user(),
                $uuid,
                DonorEmailTemplate::from($request->validated('template'))
            )
        );
    }

    /**
     * Read a donor's health questionnaire, Sections I-A to I-C.
     *
     * Separate from the scan for a reason: the scan says who is at the counter,
     * this hands over thirty declared health answers. Separate route, separate
     * ability, separately audited.
     *
     * `screening_id` is an optional pin carried through from the scan, which
     * makes the read exact. Without it -- the manual valid-ID path -- the
     * service resolves the screening itself and reports which rule it used.
     */
    public function healthQuestionnaire(Request $request, string $uuid): JsonResponse
    {
        $screeningId = $request->integer('screening_id') ?: null;

        return response()->json(
            $this->donorQuestionnaireService->show($request->user(), $uuid, $screeningId)
        );
    }
}

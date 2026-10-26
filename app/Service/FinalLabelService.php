<?php

namespace App\Service;

use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use App\Repository\ClearanceRepository;
use App\Repository\LaboratoryRepository;
use App\Support\BagExpiry;
use App\Support\BagNumbers;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Processing's final (Phase 2) labels.
 *
 * Final labelling is the component lab's step (project owner, 2026-10-22):
 * once TTI Testing and Immunohematology have both cleared a donation,
 * Processing prints a final label for every bag it declared — the verified
 * blood type, the expiry and the clearance codes — and hands the labelled bags
 * over. Issuance books them straight into stock.
 *
 * The labels are built from the breakdown, not from inventory rows: the bags
 * are not in inventory yet. Each bag's number is its Phase 1 number (the
 * donation's sticker plus the component), which is the unit id Stock Intake
 * books it under, so the label and the stock record name the same bag.
 */
class FinalLabelService
{
    public function __construct(
        private readonly LaboratoryRepository $laboratoryRepository,
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly ClearanceRepository $clearanceRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Print, or reprint, a donation's final labels, and record who did and when.
     *
     * Hand-over requires the labels (LaboratoryService::guardReadyToComplete),
     * and a correction that changes what they say clears the record, so they
     * are printed again.
     *
     * @return array<string, mixed>
     */
    public function print(User $staff, int $donationId): array
    {
        $facility = $this->requireFacility($staff);
        $settings = $this->bloodComponentRepository->settingsFor($facility->id);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $settings): Donation {
            $donation = $this->laboratoryRepository->lockDonation($donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            $this->guardLabellable($donation, $settings);

            $donation->labelled_at = now();
            $donation->labelled_by = $staff->id;
            $donation->save();

            return $donation;
        });

        $labels = $this->labelData($donation, $settings);

        $this->auditLogger->record($staff, 'laboratory.labels_printed', $donation, [
            'facility_id' => $facility->id,
            'bags' => count($labels['units']),
        ]);

        return [
            'message' => count($labels['units']) === 1
                ? 'Final label ready. Affix it before handing the bag over.'
                : count($labels['units']).' final labels ready. Affix them before handing the bags over.',
            ...$labels,
        ];
    }

    /**
     * Refuse a donation whose bags cannot honestly carry a final label.
     *
     * A label says the bag is cleared for issue, with its type and expiry, so
     * every one of those must be known and true when it is printed.
     *
     * @param  SupportCollection<int, FacilityBloodComponent>  $settings
     */
    private function guardLabellable(Donation $donation, SupportCollection $settings): void
    {
        if ($donation->status === DonationStatus::Rejected) {
            throw $this->refuse(
                409,
                'donation_rejected',
                'This donation was rejected, so its bags can never be labelled for issue.'
            );
        }

        if (! in_array($donation->status, [DonationStatus::Collected, DonationStatus::Tested, DonationStatus::Completed], true)) {
            throw $this->refuse(
                409,
                'donation_not_collected',
                "A donation that is {$donation->status->label()} has no bags to label."
            );
        }

        $slots = BagNumbers::slots($donation);

        if ($slots === []) {
            throw $this->refuse(
                409,
                'components_missing',
                'Record the component breakdown before printing final labels.'
            );
        }

        if (! $this->clearanceRepository->has($donation->id, ClearanceKind::Tti)) {
            throw $this->refuse(
                409,
                'tti_not_cleared',
                'TTI Testing has not cleared this donation, so its bags cannot be labelled yet.'
            );
        }

        if (! $this->clearanceRepository->has($donation->id, ClearanceKind::Immunohematology)) {
            throw $this->refuse(
                409,
                'immunohematology_not_cleared',
                'Immunohematology has not cleared this donation, so its bags cannot be labelled yet.'
            );
        }

        $donation->loadMissing('donorProfile');

        if ($donation->donorProfile?->blood_type_id === null) {
            throw $this->refuse(
                422,
                'donor_blood_type_missing',
                'This donor has no blood type on file, so the labels would have no type to print.'
            );
        }

        // Every bag is labelled by its number, which Stock Intake books it
        // under. A donation without a barcode, or a breakdown recorded before
        // volumes were kept, has bags without one.
        $bags = array_merge(...array_values($slots));

        if (BagNumbers::barcode($donation) === null) {
            throw $this->refuse(
                409,
                'barcode_missing',
                'This donation has no barcode, so its bags have no numbers to print. Correct the collection record first.'
            );
        }

        if (in_array(null, array_column($bags, 'bag_number'), true)) {
            throw $this->refuse(
                409,
                'bags_unnumbered',
                'Some bags were recorded before bag numbers were kept. Correct the breakdown on the Processed list first.'
            );
        }

        foreach (array_keys($slots) as $componentId) {
            if (! ($settings->get($componentId)?->hasShelfLife() ?? false)) {
                $name = DonationComponent::query()
                    ->with('component')
                    ->where('donation_id', $donation->id)
                    ->where('component_id', $componentId)
                    ->first()?->component?->name ?? 'A component';

                throw $this->refuse(
                    409,
                    'shelf_life_missing',
                    "{$name} has no shelf life set, so its expiry cannot be printed. Ask a supervisor to set it under Blood Components."
                );
            }
        }
    }

    /**
     * What the final label prints, for every bag Processing declared.
     *
     * The blood type is the donor profile's, which the typing guard keeps equal
     * to Immunohematology's cleared reading. The clearance codes are the
     * tokens' own ids, since a token has no code of its own. No donor name: a
     * label travels with the bag, and the bag is blind.
     *
     * @param  SupportCollection<int, FacilityBloodComponent>  $settings
     * @return array<string, mixed>
     */
    private function labelData(Donation $donation, SupportCollection $settings): array
    {
        $donation->loadMissing(['collection', 'facility', 'clearances.issuer', 'donorProfile.bloodType', 'labeller']);

        $rows = DonationComponent::query()
            ->with('component')
            ->where('donation_id', $donation->id)
            ->get()
            ->keyBy('id');

        $labelledBy = $donation->labeller
            ? $donation->labeller->signatureName()
            : null;

        $bags = [];

        foreach (BagNumbers::slots($donation) as $componentId => $slots) {
            foreach ($slots as $slot) {
                $bags[] = [
                    'row_id' => $slot['row_id'],
                    'unit_id' => $slot['bag_number'],
                    'blood_type' => $donation->donorProfile?->bloodType?->code,
                    'component' => $rows->get($slot['row_id'])?->component?->name,
                    'volume_ml' => $slot['volume_ml'],
                    'expiry_date' => BagExpiry::date($donation, $settings->get($componentId)?->shelf_life_days),
                    'labelled_at' => $donation->labelled_at?->toIso8601String(),
                    'labelled_by' => $labelledBy,
                ];
            }
        }

        // In the order the bags were declared, as the Phase 1 labels print.
        usort($bags, fn (array $a, array $b): int => $a['row_id'] <=> $b['row_id']);

        return [
            'donation_id' => $donation->id,
            'donation_barcode' => BagNumbers::barcode($donation),
            // The day the blood was drawn: the day every bag's expiry is
            // counted from (BagExpiry), printed beside the volume.
            'collected_on' => $donation->donation_date === null ? null : OperationalDay::dateOf($donation->donation_date),
            'facility' => $donation->facility?->name,
            'labelled_at' => $donation->labelled_at?->toIso8601String(),
            'labelled_by' => $labelledBy,
            'clearances' => $donation->clearances
                ->sortBy(fn ($token): int => $token->kind === ClearanceKind::Tti ? 0 : 1)
                ->map(fn ($token): array => [
                    'kind' => $token->kind->value,
                    'label' => $token->kind === ClearanceKind::Tti ? 'TTI cleared' : 'ABO/Rh cleared',
                    'code' => ($token->kind === ClearanceKind::Tti ? 'TTI-' : 'IH-').str_pad((string) $token->id, 6, '0', STR_PAD_LEFT),
                    'issued_at' => $token->issued_at?->toIso8601String(),
                    'issued_by' => $token->issuer
                        ? $token->issuer->signatureName()
                        : null,
                ])->values()->all(),
            'units' => array_map(function (array $bag): array {
                unset($bag['row_id']);

                return $bag;
            }, $bags),
        ];
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}

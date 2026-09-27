# RedAgos Implementation Decisions

These are **IMPLEMENTATION DECISIONS confirmed during development**, not Capstone 1 academic requirements. The Capstone 1 source is `RedAgos-Capstone-1.pdf`. Conflicts must be recorded and resolved explicitly; no code/schema change is authorized by this file alone.

## Facility ownership

**DECISION:** Each facility staff user belongs to exactly one facility through `users.facility_id → facilities.id`. Use this server-side association for facility isolation. Do not add a facility-user pivot unless requirements later need staff to work across facilities.

**CURRENT IMPLEMENTATION CONFLICT:** `users` has no `facility_id`; `role_user` has no facility column; no facility-user pivot exists. Facility isolation cannot yet be enforced from authenticated staff membership.

## Blood inventory authority

**DECISION:** Individual `blood_units` records are authoritative physical-bag records. Batch/inventory summaries must be derived from units and must not become a second source of truth through independent available-unit counts.

**CURRENT IMPLEMENTATION:** The database has `blood_units`, but no inventory model/API layer currently implements this decision.

## Blood unit origin

**DECISION:** Every `blood_unit` must trace to a donation through `blood_units.donation_id → donations.id`. Do not create orphan/manual units unless approved requirements add external supply, transfers, or another origin. Treat an inventory “Add Batch” interface as a collection/donation inventory workflow rather than arbitrary stock entry.

**CURRENT IMPLEMENTATION:** The current migration has a non-null donation foreign key on `blood_units`, consistent with this decision.

**CAPSTONE CONFLICT:** The paper's inventory storyboard permits recording blood units that are “newly collected or received.” “Received” may require an external-supply or transfer origin, which this decision forbids. Clarify the intended meaning and data model before inventory implementation.

## Blood center registration

**DECISION:** Blood-center registration creates the facility and user, links the user with `users.facility_id`, places both into a pending state, requires administrator approval, and grants active blood-center access only after approval. Email verification is not organizational approval.

**CAPSTONE GAP:** The paper assigns facility registration and role assignment to system administrators but does not specify self-registration, pending approval, or email-verification behavior. This decision is a proposed implementation workflow, not a paper requirement.

**CURRENT IMPLEMENTATION CONFLICT:** The blood-center registration page is a UI stub, no matching API route exists, no facility link/status exists, and the existing role middleware checks role membership only.

## Facility creation during registration

**DECISION:** A registering blood center creates its facility. Use a DOH license number as a unique duplicate-registration guard if supported by the schema; do not allow duplicate facility records for the same license number.

**CURRENT IMPLEMENTATION CONFLICT:** `facilities` currently has no DOH license-number column or unique constraint. Do not add one until the planned schema change is explicitly implemented and checked against Capstone documentation.

## Current implementation scope

**DECISION:** The current pass is limited to blood-center registration, facility creation/linkage, user/facility association, authentication/authorization foundation, reference data, and blood-center inventory. Hospital blood-bank registration is deferred unless the existing project explicitly requires it.

**CURRENT IMPLEMENTATION:** The client also contains hospital pages, but no completed hospital-registration backend workflow was found.

## Development order

**DECISION:** Implement in this order: (1) facility linkage; (2) organization/authentication foundation; (3) reference data; (4) role/facility routing and authorization; (5) inventory; (6) incoming blood requests; (7) request allocation/fulfillment.

Do not build fulfillment on unresolved facility-isolation or inventory foundations.

## Clinical configuration boundary

**DECISION:** Do not invent blood-component shelf-life or storage-temperature values. Before expiry derivation or stock entry relies on them, obtain values and governing source from a named clinical owner.

**CURRENT IMPLEMENTATION:** The current `blood_components` table only has name and price, and no component seeder exists.

## Conflicts to resolve against Capstone 1

- The paper does not define staff-to-facility ownership, cross-facility access, or organization onboarding/approval; these remain implementation decisions requiring stakeholder confirmation.
- The paper allows inventory records described as newly collected or received, which conflicts with the donation-only blood-unit-origin decision.
- The current schema/implementation lacks the facility linkage, approval state, and DOH license guard required by the decisions above.
- `request_allocations.unit_id` is globally unique, which may conflict with a future release-and-reallocate workflow.
- The hospital UI appears to require blood-request details/states that do not directly correspond to the current schema. Resolve the contract before implementation.

## Donation status that may become issuable stock (Module 3 blocker)

**DECISION:** `donations.status = completed` means the donation has finished transfusion-transmissible-infection testing and is **cleared for issue to a patient**. Blood-unit intake gates on `completed` and creates units as `available`. This is "Branch A" of the Module 3 plan.

**DECIDED BY:** The project owner, on 2026-08-25, on the evidence of the schema's own ordering: `donations.status` is declared `registered | screening | collected | tested | completed | rejected` in `2026_07_06_000010_create_donations_table.php`, where `tested` precedes `completed`. Read in sequence, a donation cannot reach `completed` without having passed `tested`.

**STILL OUTSTANDING:** Clinical sign-off from the capstone adviser or the partner blood center has not been obtained. Record the confirming person's name and role here when it is. If that confirmation contradicts this reading, the intake gate is a single constant in `InventoryService`, but the module would then need the quarantine lifecycle ("Branch B"): a sixth `quarantined` unit state, units created held-back rather than available, a source of truth for test results, and a release path.

**CAPSTONE GAP:** The paper does not define the valid donation-status transitions (`CAPSTONE_CONTEXT.md` Sec. 12), and its five blood-unit states (Sec. 8) contain no quarantined or untested state. Nothing in the server writes a donation status today, so the codebase could not answer this either way.

**RESOLVED CONFLICT:** `Donation::scopeCompleted()`'s docblock claimed "donations that reached collection" while the query filtered on `completed` — two different claims on the exact distinction this decision turns on. The docblock now matches the query.

## Module 3 implementation decisions

**DECISION (D2):** Expiry is entered by staff per unit, read off the physical bag. `blood_components.shelf_life_days` stays NULL and is not consulted, so the unowned clinical constant does not gate inventory.

**DECISION (D3):** Units are donation-derived only. `blood_units.donation_id` stays NOT NULL; there is no free stock entry.

**DECISION (D5):** Intake serialises on the donation row with `SELECT ... FOR UPDATE`, derives the unit-id sequence under that lock, and still catches a unique violation — retrying a generated id, and returning a 422 field error for a staff-supplied one.

**DECISION (D6):** `expired` is a stock state, not a disposal. `expired -> discarded` is legitimate, and `expired_at` and `discarded_at` are separate nullable timestamps so discarding an expired unit does not erase when it expired.

**DECISION (D7):** The expiry sweep is registered in `routes/console.php` at 00:30 `Asia/Manila`, `withoutOverlapping()->onOneServer()`. Deployment must install cron; `ScheduleRegistrationTest` fails the build if the registration is removed.

**DECISION (D8):** `expiry_date` accepts today (`after_or_equal`) against the operational date, because the sweep only expires `expiry_date < today`.

**DECISION (D10):** The sweep audits per unit — one `inventory.expired` row per unit moved, plus one `inventory.expiry_swept` run row written on every run, including runs that move nothing.

**DECISION (D11):** The sweep never writes on the strength of its own selection. Each chunk re-reads its candidates under `lockForUpdate()`, re-asserting `status = available AND expiry_date < :operational_date`, and the update plus its audit rows commit in one transaction with those predicates repeated in the `WHERE`.

**DECISION (operational day):** "Today" for expiry comes from `config('blood_center.timezone')` via `App\Support\OperationalDay`, not from PHP's ambient timezone, so the sweep, the validation rules and `days_remaining` cannot disagree.

**CURRENT IMPLEMENTATION CONFLICT:** Reserve/release, stock thresholds, inter-facility transfers, label printing and trend history are out of Module 3's scope. The sweep touches `available` only, so a `reserved` unit can pass its expiry and keep saying `reserved` until the allocation module can release it. The client's inventory page offers an `archive` action that no status backs.

## Department ownership of the department structure (Phase 1-4)

**DECISION:** Blood-centre staff carry two orthogonal columns. `users.department`
(`collection | laboratory | inventory | billing`) says which operational area a
staff member works in; `users.is_supervisor` says whether they hold the
management level. A supervisor holds the full permission set regardless of
department, so a working supervisor's department only decides their default
landing page. Permissions are a fixed department-to-abilities matrix in
`App\Support\DepartmentPermissions`, registered as Laravel gates and enforced
with the built-in `can:` route middleware. There is no permissions table.

**DECIDED BY:** The project owner, on 2026-08-26.

**CAPSTONE GAP:** The paper defines no department entity. Its data dictionary
does describe `role.role_name` as "Predefined unique role (if facility role per
department)", and its storyboards already use distinct actors inside one centre
("Blood Center Staff", "Authorized Inventory Personnel"), so departments are a
refinement consistent with the paper rather than a contradiction of it. The
mechanism above is an implementation decision, not a paper requirement.

**NOT USED:** `Gate::before()` would return true ahead of every policy in the
application, including `DonationAppointmentPolicy`, and would hand a supervisor
ownership of every donor's appointment. The supervisor earns each ability by
holding it in the matrix instead.

---

## Who records blood component information (Module 4/5 blocker)

**DECISION:** Laboratory/Processing declares, Inventory records. Laboratory
records the processing outcome for a donation — which components were separated
out of it, and the expiry read off each physical bag. Inventory still creates
the `blood_units` rows, but `units[].component_id` is constrained to what
Laboratory declared for that donation rather than being free-typed.

`blood_units.blood_type_id` is unaffected: it stays derived server-side from the
donation's donor profile and is never accepted from a client, under either
department.

**DECIDED BY:** The project owner, on 2026-08-27.

**CONFLICT THIS RESOLVES:** `docs/BLOOD-CENTER.md` assigns Laboratory "Record
blood type and blood component information" while assigning Inventory "Record
newly collected or received blood units" and only "**Monitor** blood inventory by
blood type and component". The Capstone inventory storyboard instead has
"Authorized Inventory Personnel" typing Blood Type and Blood Component into the
intake form — but the paper describes no laboratory department at all, so it has
nowhere else to put the field. The decision reads the two together: Laboratory
records the component *information*, Inventory records the *units* from it.

**PRECEDENT:** The paper's storyboard field list is already deliberately not
implemented as written — `blood_type` is derived rather than entered, because it
is the one field on a unit that can kill someone if it is wrong. Departing from
that storyboard on the same grounds is consistent, not novel.

**CURRENT IMPLEMENTATION CONFLICT:** `StoreBloodUnitsRequest` validates
`units.*.component_id` as `exists:blood_components,id` with nothing tying it to
the donation. Until the processing-results table exists, Inventory can still
record a component the laboratory never produced.

---

## Who creates a donation and owns its status (Module 3 blocker, resolved)

**DECISION:** Donor/Collection creates the donation at check-in and owns
`registered -> screening -> collected`, together with the `blood_collections`
row whose `collected_by` names the collecting staff member.
Laboratory/Processing owns `collected -> tested -> completed`. Either department
may record `rejected`.

| Status | Owner | Ability |
|---|---|---|
| `registered` | Donor/Collection (check-in) | `donations.record` |
| `screening` | Donor/Collection | `donations.record` |
| `collected` | Donor/Collection | `donations.record` |
| `tested` | Laboratory/Processing | `lab.update_status` |
| `completed` | Laboratory/Processing | `lab.update_status` |
| `rejected` | either | `donations.record` or `lab.update_status` |

**DECIDED BY:** The project owner, on 2026-08-27.

**SUPERSEDED IN PART:** Laboratory/Processing was split into Testing and
Processing on 2026-09-26; see "Blood-centre departments: five, not four". Testing
now reaches `tested` by recording a result, and Processing owns `completed` and
the laboratory side of `rejected`.

**WHY:** `docs/BLOOD-CENTER.md` gives Donor/Collection "Record completed blood
donations" and "Manage donor queues", and gives Laboratory "Receive blood
collection information for processing", "Record and update blood processing
status" and "Update blood status when processing requirements have been
completed". The paper's donation BPMN states the ordering directly: "Screening
results are recorded, validated, and saved within the system before blood data is
officially recorded."

**EFFECT ON MODULE 3:** None. `InventoryService::ISSUABLE_DONATION_STATUS`
remains `completed`; this decision only names Laboratory as what sets it, which
is the Branch A reading already recorded above on 2026-08-25. The clinical
sign-off noted there is still outstanding.

**CAPSTONE GAP:** The paper's `donation` data dictionary has **no status column**
— only `donation_id`, `donor_id`, `facility_id`, `appointment_id` and
`donation_date`. `donations.status` and `donations.volume_ml` are implementation
additions. The table's own description narrates exactly this lifecycle
("from initial registration through screening, collection, testing, and final
completion or rejection"), so the enum is well-supported by the paper's prose
even though it is absent from its field list.

**UNRESOLVED:** `donations` has no `rejection_reason` column, so a rejected
donation cannot record why. The paper requires a recorded reason for a rejected
*request* but says nothing about a rejected donation. Decide before building the
Donor/Collection module.

---

## Laboratory/Processing schema (Phase 5, module 2)

**DECISION:** Two new tables carry what the laboratory records, neither of
which appears in the Capstone data dictionary:

- `donation_test_results` — one row per donation (unique `donation_id`). Holds
  the screening outcome (`passed | reactive | inconclusive`), the blood type the
  laboratory typed, who entered it, when, and free-text notes. A correction
  edits the row rather than adding a second, so there is never an ambiguity
  about which result cleared the blood.
- `donation_components` — which components the donation was separated into and
  how many bags of each, unique on `(donation_id, component_id)`. This is the
  declaration blood-unit intake is constrained to.

**DECIDED BY:** The project owner, on 2026-08-27, as the schema needed to
implement the Laboratory responsibilities in `docs/BLOOD-CENTER.md`.

**CAPSTONE GAP:** The paper describes no laboratory department and defines no
table for screening results or component yield. Its `donation` table carries no
status column either (recorded above). These tables are additions required by
the finalised organisational structure, not paper requirements.

**SCOPE BOUNDARY:** RedAgos does not perform the assay. `donation_test_results`
records what a qualified professional reported; `recorded_by` names the staff
member who entered the record, not the professional who produced it. Nothing in
`LaboratoryService` computes, infers or derives a result. *(Superseded in part
by "Section II" below: the overall result is now rolled up from the recorded
immunohematology and five serology readings. No reading is inferred.)*

**DECISION (blood-type mismatch):** If the type the laboratory reads off the bag
differs from the donor profile, recording the result is refused with
`blood_type_mismatch` rather than either value silently winning. A person's
blood type does not change, so a mismatch means one of the two records is wrong,
and `blood_units.blood_type_id` is derived from the donor profile — letting it
through would put a unit into stock labelled with a type the laboratory did not
read. Correcting the donor profile is a Donor/Collection action.

**DECISION (what `completed` requires):** A donation may only be cleared for
issue when it is `tested`, has a recorded result of `passed`, and has a declared
component breakdown. A `reactive` or `inconclusive` donation can never reach
`completed` by any route, and `completed` is what blood-unit intake gates on —
so this is the rule that keeps untested blood away from a patient.

**DECISION (intake constraint):** `InventoryService` now refuses a unit whose
component the laboratory did not declare for that donation (422 on the field),
and refuses an intake that would exceed the declared quantity (409
`exceeds_declared_quantity`). The count includes units already recorded, so the
limit holds across separate intakes rather than only within one request. This
resolves the CURRENT IMPLEMENTATION CONFLICT recorded under "Who records blood
component information".

**CONSEQUENCE:** The donor-to-inventory chain is now closed end to end, proved
by `DonationToInventoryChainTest`. Before Laboratory existed nothing wrote
`completed`, so the finished inventory module was unreachable: no code path
could produce a donation it would accept.

**UNRESOLVED:** `donations` still has no `rejection_reason` column, so neither a
collection-side nor a laboratory-side rejection records why. The notes field on
`donation_test_results` covers the laboratory case in practice but is not a
structured reason.

## Blood request form (DOH Blood Request Form, Adult)

**SOURCE:** A copy of the Philippine DOH *Blood Request Form (For ADULT)* was
supplied as the document a blood request must produce. The paper does not
contain this form, so everything below is an implementation decision, not a
Capstone 1 requirement. `CAPSTONE_CONTEXT.md` does record "patient information
in blood requests" as a REQUIREMENT, and the patient columns close that gap.

**DECISION (request purpose):** `blood_requests.request_purpose` is either
`patient_transfusion` or `replenishment`, and is a separate axis from
`urgency_level`. The two must never be merged: a restock can be STAT and a
named-patient transfusion can be routine, and one column cannot express both.
A transfusion carries patient identity; a replenishment restocks the
requester's own shelves and carries none, so the form's patient block prints
blank and the purpose is stated on the sheet instead.

**DECISION (patient fields captured):** Surname, first name, middle name, age
and sex only, plus the existing `blood_type_id`, which serves as the patient's
blood type on a transfusion and the requested unit type on a restock. The
form's remaining patient-block fields — attending physician, ward, room number,
hospital number, clinical diagnosis and previous-transfusion history — are
**not** captured and print as ruled blanks for handwriting. Capturing them was
considered and deferred; adding them later is additive.

**DECISION (one request, several components):** `blood_requests.component_id`
and `blood_requests.quantity` moved to `blood_request_items`, one row per
component, each with its own quantity and indication code. The form is a
checklist that permits several components on one sheet, and the previous grain
forced three components to become three requests with three reference numbers.
The header's `quantity` is now an accessor summing the lines rather than a
stored column, because a stored copy would be a second source of truth — the
same rule this file already applies to inventory summaries.

**CONSEQUENCE:** `request_allocations.request_item_id` names which line a held
unit answers. Without it there is no way to say how much of the platelet line
is covered, and `BillingService` cannot price a hold whose component it cannot
name. Allocation now computes outstanding per line and walks the lines in form
order; billing totals per line at the fulfilling facility's own component
prices.

**DECISION (indication codes):** The form's 27 codes (WB, R, WP, P, C, F) live
in the `App\Enums\IndicationCode` enum, not a seeded table. They are fixed
national reference data, not a per-facility setting. Each code is tied to its
component by **name**, because `blood_components.name` is unique and is what
`BloodComponentSeeder` seeds by. `GET /api/hospital/reference-data` projects
them so the client cannot drift into offering a code the API would reject.

**DECISION (indication is conditionally required):** An indication is required
only for the six components the form prints codes for. A component outside that
set has no box to tick, and demanding one would make it unrequestable. The six
"Others" codes additionally require free text, because the form says they
trigger a review of the indication, which cannot happen against a blank.

**DECISION (Washed RBC):** Added to `BloodComponentSeeder`. The form carries a
WP block for it and a component the form names but the table does not hold
cannot be requested.

**DECISION (ROUTINE / STAT is a label):** `urgency_level` keeps its
`routine | emergency` values. The form and the request UI display STAT for
`emergency`. Renaming the enum would have touched the triage scope, the
emergency banner on both portals, the submission notification and their tests,
to no behavioural gain.

**DECISION (rendered server-side):** `barryvdh/laravel-dompdf` renders
`resources/views/pdf/blood-request-form.blade.php`, streamed as an attachment
from `GET /api/hospital/blood-requests/{id}/form` and
`GET /api/blood-center/blood-requests/{id}/form`. One `BloodRequestFormService`
serves both, so the requesting hospital and the fulfilling centre cannot hold
two copies of a clinical document that disagree. Nothing is written to storage:
the form is derived from the request and re-renders identically on demand.

**UNRESOLVED:** The handover half of the form — type of crossmatching, number
of donors provided, screened/unscreened counts, remarks, and the received-by
and extracted-by signatures with their timestamps — is printed blank and filled
in on paper. Capturing it would mean a handover step at the blood centre that
no module currently charters.

## Government subsidy on a blood request statement

**DECISION (subsidy is a decision, not a price of zero):** `BillingStatus` gains
a `Subsidised` case. A statement is still raised at the fulfilling facility's
own component price when stock is reserved; billing staff then either record a
payment or apply the subsidy, which writes the balance to zero and clears the
request for release. It clears release exactly as `Paid` does.

**WHY NOT reuse `Paid`:** "nothing was owed" and "the money was collected" are
different facts, and `Billing`'s own docblock already warned that reports
"should not claim the network collected fees it never charged". With a zero
total and a `Paid` status those two cases are indistinguishable. A statement
raised at zero because the component carries no price stays `Paid` — nothing
was waived, there was simply nothing to charge.

**WHY NOT `Void`:** voiding says the statement should never have existed. A
subsidised statement was correctly raised and correctly settled.

**DECISION (who decides, and when):** Billing staff, on the statement, behind
`billing.record_payment` — waiving a charge and taking money for one both
decide that nothing further is owed, and both release blood. Inventory staff
approving a request do not make a billing decision.

**CONSEQUENCE:** `BillingService::syncFor()` now skips any statement settled by
decision, not just voided ones. Allocating further units against a subsidised
request must not revive a balance somebody waived, nor re-block a release
already cleared.

**DECISION (money already collected survives a later subsidy):** Payments are
left in place when a part-paid statement is subsidised. Deleting them would
destroy the only record the money was received; the refund is settled outside
this system. The waived amount and anything already collected are both written
to the audit log, because the statement itself no longer carries them.

**UNRESOLVED:** The hospital has no view of its own statement. There is no
`/hospital/.../billing` route, and `useBloodRequestBilling.js` calls endpoints
that do not exist. A requester currently learns what was charged, or that the
subsidy covered it, only by being told.

## Fulfillment: the three states a unit actually has

**DECISION:** The blood-centre fulfillment screen tracks
`allocated -> released -> received` and nothing else. The eight-stage pipeline
the page previously drew — Preparing, Quality Check, Ready for Dispatch,
Dispatched, Delivered, Completed — exists in no table, was driven by a mocked
`$fetch`, and with mocks off called `/blood-center/fulfillment*` routes that
were never served.

**CONSEQUENCE:** Quality control before dispatch is not represented. If it needs
to be, it is a Laboratory concern with its own states, not a relabelling of
`allocated`.

**DECISION (receipt stays with the requester):** Confirming arrival is done from
the hospital's request page. The dispatching centre cannot assert on the
hospital's behalf that blood arrived, which is why the endpoint has always sat
on the requester side of the API. *(Receipt no longer moves the request's
status — fulfilment is counted at dispatch; see "Walk-in requests, partial
fulfilment and follow-ups" at the end.)*

## Blood Donor's Health Questionnaire (DOH-DCHD-RD-SNBC-DMS-FORM002)

**SOURCE:** A copy of the DOH *Blood Donor's Health Questionnaire* used by
Sub-National Blood Center – Mindanao, effectivity 3 July 2023, was supplied as
the form the counter must be able to read on screen. Sections I-A (Personal
Data), I-B (Donor History, 29 questions) and I-C (Informed Consent) are in
scope. Section I-D onward — physical examination, serology, phlebotomy — is
not, and neither are the form's margin boxes (Sleep/Meal/Meds/Allergies,
DH/DS), which are the screening officer's own working notes. *(Since
superseded: Section I-D and the margin boxes are recorded — see "Section I-D:
Physical Examination" — and Section II — see "Section II" at the end.)*

**DECISION (the donor side does not judge):** RedAgos no longer scores a
donor's own questionnaire answers into a verdict. Every screening is recorded
`result = pending` — answered, awaiting the blood centre's decision — and a
complete submission always mints a QR code. The donor is shown no pass, no
deferral and no reasons. Two things drove this. The form says on its face that
"A 'YES' answer may not necessarily exclude you from blood donation", and this
file's own *Clinical configuration boundary* says RedAgos records what
qualified personnel reported rather than computing clinical outcomes. Applying
that rule to the donor side is what this change is.

**CONSEQUENCE:** `eligibility_screenings.result` is always `pending` for new
rows. `EligibilityScreening::scopeCurrentlyValid()` therefore had to stop
filtering on `result = eligible` and now means only "answered and unexpired" —
leaving that filter would have made every new screening invisible to
`currentValidScreening()`, breaking the QR refresh and the re-screen guard
without raising anything. `QuestionnaireVersionTest` guards it.

**CONSEQUENCE:** `submitted_result` is no longer written and the client no
longer submits a verdict, so the divergence-detection audit went with it.
`computed_result` is still written: it is the server's advisory read, shown to
the counter and never to the donor.

**DECISION (thresholds still refuse):** Age, weight and the 56-day donation
interval remain hard refusals on submission, because they are arithmetic on
records the donor cannot forge rather than readings of their answers. They are
reported as plain statements of the threshold — "Donors must weigh at least 50
kilograms" — never in the language of a health verdict.
`EligibilityRuleEvaluator` is split along exactly that seam.

**DECISION (review markers, not deferral triggers):**
`eligibility_questions.disqualify_if_answer` keeps its name and its meaning for
version 1, but nothing defers on it any more. A set flag highlights that row in
the counter's questionnaire drawer so a nurse's eye lands on it. This inverts
the risk the *Clinical configuration boundary* warns about: a wrong flag now
costs a second glance rather than turning away a real donor. Version 2's flags
follow version 1's precedent where one exists, plus the answers that plainly
warrant a look; a clinical owner can revise them with an UPDATE.

**DECISION (version 1 keeps working):** Bumping
`config('donation.questionnaire_version')` to 2 revokes nothing. A QR is a
credential presented by a person standing at a counter, and a bulk revoke would
strand donors mid-visit while leaving no per-donor audit row. The counter is
told instead: the payload carries `question_version` and `is_current_version`,
and the drawer says the donor answered an older form and names what is missing.
A version 1 screening also predates consent, so it renders an explicit
"no consent is on file" rather than a blank date — that gap read as a consent
that was given is the worst failure this record can produce.

**CONSEQUENCE:** the question bank is append-only. Never delete, and never
deactivate, a version that has screenings against it: the counter resolves a
historical answer's wording by `[version, code]`. `scopeForVersion()` filters
`is_active` and is therefore wrong for that read, which is why
`EligibilityRepository::questionTextMap()` exists without the filter. Version 2
codes are prefixed `v2_` so a cross-version mix-up is impossible by
construction — `eligibility_screening_answers` stores a code but not a version.

**DECISION (question 5 is omitted, not answered falsely):** The form's question
5 is for female donors. Applicability is resolved server-side from the stored
gender and the question is left out of the payload entirely for male donors,
rather than answered `false` — "not pregnant" from someone the question was
never put to is a falsehood in a clinical record. A donor whose gender is
`other`, `prefer_not_to_say` or unrecorded is offered it and not required to
answer: dropping a physiological safety question over a privacy choice is the
wrong way to be discreet. `gender_at_screening` is snapshotted beside
`age_at_screening` so a later profile edit cannot retroactively change what the
record says was asked.

**DECISION (Section I-A is derived where records already hold it):** Type of
donor, number of times donated and date of last donation are computed from
donation records and reported with `donor_type_source: "derived_from_records"`.
`EligibilityRuleEvaluator`'s own rule is that these come from the records,
"never from the numbers typed into the questionnaire", and a donor-declared
copy beside a derived one is two contradictory answers in one payload. Only the
venue of a previous donation is stored as declared, because it may have been at
a non-RedAgos centre; it lives on the screening, not the profile.

**DECISION (I-A is collected in the profile, not at registration):** Signing up
already asks for a lot, and every I-A field can be filled in later from the
donor's profile, which is where they are edited anyway. Registration therefore
does not ask for them. Existing donors cannot be back-filled in any case, so
the counter renders every gap as "Not provided" rather than as a blank line
that reads like an unanswered question on the paper form.

**DECISION (the questionnaire is gated on presentation, not relationship):**
`DonorDirectoryService::present()` withholds a donor's record from a centre they
have never donated at, on the grounds that detailed records stay with the
facility that created them. That rule does not apply here and applying it would
withhold the questionnaire from every first-time donor — the ones whose answers
most need reading. The questionnaire is not another facility's record; it is the
donor's own declaration, addressed to whichever centre they hand it to. So the
gate is presentation: an appointment here today, an open donation here, a QR
verified at this counter today, or an existing donation relationship. This is
also stricter than `donors.view` alone, because it closes the hole where any
Collection member could pull a questionnaire for a donor found by browsing the
directory.

**CONSEQUENCE:** a new ability, `donors.view_questionnaire`, held by Collection
alone. Reading thirty declared health answers is a different act from looking a
donor up, and Laboratory — which holds `donations.view` — cannot reach it. The
scan response carries only a reference; the document has its own route, its own
ability and its own audit entry, with no answers in the audit context.

## Appointment screening window

**DECISION (book first, answer the day before):** `AppointmentService::book()`
no longer requires a valid screening. A donor books freely and the questionnaire
falls due in a window opening the day before the appointment, so that what the
blood centre reads describes the donor as they are now rather than as they were
up to 90 days ago. `appointment_screening_window_days` joins the file's three
independent time rules as a fourth: `screening_validity_days` is how long an
answer stands, this is how early it may be given, and a donor with no
appointment is not subject to it.

**DECISION (the window closes at the end of the appointment day):** Not at the
booked time. A donor who never answered has no QR, so the remedy is to fill it
in at the counter — and a window that shut at the booked time would be shut for
exactly the person who needs it. It also gives donors a rule they can state
without checking their booking: the day before, or the day of.

**CONSEQUENCE:** both bounds are computed through `AppointmentScreeningWindow`,
built on `OperationalDay`. `config/app.php` defaults to UTC while deployment
runs in Manila, and under UTC Manila's 00:00-08:00 still reads as the previous
date — a window computed from a bare `now()` opens eight hours late for every
Manila donor and still passes a UTC test suite.

**CONSEQUENCE:** rescheduling out of the window revokes the QR, and so does
cancelling. A credential minted for one visit must not outlive it. The QR's
expiry is capped at the end of the appointment day for the same reason.

**CONSEQUENCE:** the reminder is load-bearing, not a courtesy. With the booking
gate gone, `donors:open-screening-window` is the only thing between a donor
booking and arriving with nothing to scan. It is registered in
`routes/console.php` alongside the expiry sweep, for the reason stated there:
the command existing is not the command running.

**DECISION (a donor may book while their last questionnaire was flagged):** A
flagged questionnaire is not a bar. It may have been answered months ago, and
the one that counts is answered the day before. The appointment list says what
is outstanding rather than implying all is well.

## Section I-D: Physical Examination

**SOURCE:** The block of the DOH questionnaire headed *FOR BLOOD DONOR SCREENING
OFFICER USE ONLY*, plus the four boxes in the form's top margin. It already had
a home — `donation_screenings` was created for exactly this and was already
stage 3 of the counter's donation transaction — so this completes that table
rather than adding anything beside it.

**DECISION (the four REMARKS boxes):** `donation_screenings.outcome` widens from
`qualified|deferred` to `accepted`, `temporarily_deferred`,
`permanently_deferred` and `indefinite_deferral`. Two values could not express
the difference between a donor who should come back next month and one who must
never donate again, and the system was telling every deferred donor in writing
that deferrals are usually temporary. `permitsCollection()` becomes "is
Accepted", so all three deferrals behave identically inside the donation
workflow; what separates them is what the donor is told and what the next
counter sees.

**CONSEQUENCE:** `ScreeningOutcome::label()` is an exhaustive `match` with no
`default`, so a case added without a label is a fatal error at the first
screening rather than a raw enum value printed on a clinical record. `isDeferral()`
and `isBlocking()` exist so no caller enumerates the three deferral cases
itself — one that did would silently miss a fourth if the form ever grows one.
Both `RecordScreeningRequest` and `CollectionService::recordScreening()` had a
hardcoded compare against the single old value; each now asks the outcome its
shape, which is what stops a permanent deferral being recorded unexplained.

**CONSEQUENCE (the back-fill):** existing rows migrate `qualified → accepted`
and `deferred → temporarily_deferred`. The second is the only honest reading:
every deferral on record was entered when temporariness was the only thing the
system could mean by the word, and `DonorDeferred` told those donors exactly
that. The `down()` refuses rather than guessing if any row holds a permanent or
indefinite deferral, because mapping one back onto `deferred` would tell that
donor to book again.

**CONSEQUENCE (the migration is idempotent):** the migration that created the
table called `ScreeningOutcome::values()` at migration time, so the accepted set
was baked from whatever the enum held when it ran. Now that the enum has four
cases, a fresh `migrate:fresh` builds the four-value column directly and arrives
at the widening migration with nothing to widen and no legacy rows to rewrite.
That has to be a no-op rather than an error, or CI and an existing database
diverge. Written per driver, following
`2026_09_21_120001_add_subsidised_status_to_billings_table`, whose docblock
explains why `enum()->change()` is rejected on PostgreSQL.

**DECISION (a blocking deferral gates nothing):** A permanent or indefinite
deferral is recorded, shown to the next counter, and enforced nowhere. A donor
carrying one can still book, answer the questionnaire, receive a QR and be
scanned in. This follows the boundary this file already sets — RedAgos records
what qualified personnel reported and does not judge — and the decision made for
Section I-B, where the donor's own answers stopped deciding anything. The
officer reads the banner and decides. `AppointmentService::book()` keeps its
explicit no-screening-gate comment, and the tests asserting a deferred donor can
still book are the guarantee that no block was introduced by accident.

**CONSEQUENCE:** the banner is the entire mitigation, so it has to be impossible
to miss. It sits in the pinned donor band at the top of the counter page, from
the moment the donor is verified.

**DECISION (the scan carries a reference, not the reason):** `verify-qr` gains
`prior_deferral` with the outcome, its label and the date — and no reason text.
What check-in needs is that a decision exists and when it was made; the reason is
clinical detail and belongs behind `DonorDirectoryService::history()`, which
already refuses unless the caller's facility has a relationship with the donor.
This is the same split already made for the questionnaire, and for the same
reason: the scan response is about who is standing at the counter.

**CONSEQUENCE:** `donation_screenings` has no `donor_id` — a screening belongs
to a donation, and the donation is what belongs to a donor — so every
donor-scoped question about screenings joins through `donations`, whose
`donor_id` is indexed. The lookup is deliberately not scoped to the calling
facility: a donor permanently deferred at one centre is permanently deferred, and
the counter that has never met them is the one that needs telling.

**CONSEQUENCE:** `history()` now carries the screening outcome and reason per
donation. Before this a deferred visit showed as a bare "Rejected" with the
reason recorded nowhere a colleague could find it, while the donor held the
explanation in a notification on a different page.

**DECISION (what the deferred donor is told):** `DonorDeferred` takes the
outcome. On a permanent or indefinite deferral it drops the line saying deferrals
are usually temporary and drops the "Book again" action from both the mail and
the stored card, pointing at the hotline instead. Telling someone who may never
donate again to book another appointment is the most damaging thing this feature
could have shipped, and the stored card is read days later with none of the
counter's context around it, so the button is the part most likely to be acted on
alone. A deferral recorded before the four outcomes existed passes no outcome and
keeps its original wording, which is what those donors were actually told.

**DECISION (the eight new fields are the officer's):** `general_appearance`,
`skin`, `heent` and `heart_and_lungs` are what the officer observed;
`sleep`, `meal`, `meds` and `allergies` are the margin boxes they ask the donor
in person and transcribe. All eight are entered at the counter after the scan,
and nothing on the donor side of the application writes to them.

**DECISION (where the margin boxes are filled in):** Sleep, Meal, Meds and
Allergies are printed in the top margin of the donor's questionnaire sheet, not
in the Section I-D box, so that is where the counter fills them — at the top of
the questionnaire drawer, above the tabs, rather than in the screening form
further down the page. They are the one editable part of a document that is
otherwise the donor's own declaration, and they are marked as the officer's so a
staff member is never in doubt about which author they are looking at. The
drawer's read-only guarantee narrows accordingly: it now covers Sections I-A to
I-C, which no counter may edit, and the test asserting it was rewritten to say
exactly that rather than to claim the whole drawer is read-only.

**CONSEQUENCE:** the four are held by the counter, not by either component, and
the drawer asks for a change rather than writing into what it was handed. The
screening form is what sends them, so both have to read the same object or they
drift. They save with the screening rather than on their own — a set of answers
with no screening behind them would belong to nothing — and they cannot be
entered before the donation is open, because there is no visit to record them
against. Free text and
nullable throughout, for the two reasons this table already states: no document
defines a vocabulary for any of them, and a partial record is more honest than a
mandatory field holding a placeholder. Named after the form's own labels rather
than interpreted — "Sleep:" is a ruled blank, so `sleep` and not `sleep_hours`,
because presuming a number is the same kind of invention as presuming a
vocabulary.

**CONSEQUENCE (`meds` is not the donor's questionnaire answer):** The donor
already answered "Currently taking medication?" in Section I-B, days earlier, in
the app. The counter field is never pre-filled from it. Pre-filling would turn
the officer's own finding into a confirmation of the donor's claim, and the two
are recorded in separate tables, by separate authors, precisely so they can
disagree — a disagreement between them is itself a finding. The migration that
created this table already states the rule: mixing "what the donor claimed" with
"what a qualified professional found" would put two very different kinds of claim
in one place.

**DECISION (the officer is `recorded_by`):** At a counter the person examining is
the person entering, and the column is already the authenticated user — never a
name from the request, which is what makes it worth anything. Its docblock said
it meant the typist rather than the examiner; it now means the screening officer,
and their name comes back on the donation payload for the form's signature line.
A separate `examined_by` was considered and rejected: it would introduce a name
the system cannot verify was present.

**DECISION (DH and DS are not captured):** The form's remaining two margin boxes
stay on paper. Nothing in the form or in this project names what they abbreviate,
and adding them later is additive.

**CONSEQUENCE (out of scope, noted):** `app/pages/blood-center/donors.vue` renders
a donor-history shape the server has never returned and its stats, flags and
update calls throw 501. Extending `history()` touches the endpoint that page
consumes, but the page was already broken and fixing it is separate work.

---

## Blood-centre departments: five, not four

**DECISION:** A blood centre has five operational departments instead of four:

| Value | Label | Was |
|---|---|---|
| `collection` | Collection | Donor / Collection (renamed) |
| `testing` | Testing | part of Laboratory / Processing |
| `processing` | Processing | part of Laboratory / Processing |
| `issuance` | Issuance | Inventory / Storage & Blood Request / Release (renamed) |
| `billing` | Billing / Payment | unchanged |

`App\Enums\Department` is the canonical list. `docs/BLOOD-CENTER.md` carries the
chart and each department's responsibilities.

**DECIDED BY:** The project owner, on 2026-09-26.

**DECISION (how the laboratory abilities split):** Both departments hold
`lab.view` and work from the same queue. Testing holds `lab.record_result`
(screening result and blood type, which moves a donation to `tested`).
Processing holds the new `lab.record_components` (the component breakdown) and
`lab.update_status` (clear for issue, or reject). The components route used to
sit behind `lab.record_result` and now sits behind `lab.record_components`.
Clearing a donation needs a passed result and a declared breakdown, so neither
department can clear a unit on its own record alone.

**DECISION (what kept its name):** Only departments were renamed. The
`/api/blood-center/laboratory/*` and `/inventory/*` routes, the `lab.*`,
`inventory.*` and `requests.*` abilities, and the client's
`/blood-center/laboratory` and `/blood-center/storage` pages keep their names.
They name the work, not the department that does it, and renaming them would
break links and tests for no change in behaviour.

**CONSEQUENCE (existing staff):** Migration
`2026_09_26_100001_rename_blood_center_departments` moves `inventory` to
`issuance` and `laboratory` to `testing`. Testing's abilities are a subset of
what Laboratory held, so the migration never grants anyone something they did
not have. A supervisor moves whoever works the processing bench into Processing.
`users.department` is cast to the enum, so a row still holding `inventory` or
`laboratory` fails to load. Run the migration before using the new code.

**CONSEQUENCE (owning department):** `DonationStatus::owningDepartment()` now
returns Testing for `collected` and Processing for `tested`.

---

## Section II: For Technical Management Use Only (phlebotomy and Testing)

**SOURCE:** Section II of the DOH *Blood Donor's Health Questionnaire*
(FORM002) holds three different stages under one heading: the fingerprick
Hemoglobin / Blood Type table (screening), the "For Phlebotomist Use Only" box
(collection), and the Immunohematology and Serology & NAT tables (laboratory
testing, after collection). In scope: all three. Out of scope: I-E
(post-donation care), I-F (the CUE slip), unit-level quarantine, lookback on a
donor's earlier units, and voiding a reactive result.

**DECIDED BY:** The project owner, on 2026-09-26.

**DECISION (fingerprick typing):** Recorded at screening as
`donation_screenings.fingerprick_blood_type_id`, optional. It is preliminary: it
never fills the donor profile and never pre-fills the Testing department's
typing, so a disagreement between the slide and the confirmatory typing stays
visible. No value of it, or of haemoglobin, defers anyone — the screening
officer's outcome is still the verdict.

**DECISION (the phlebotomist box):** `blood_collections` gains
`blood_bag_type` (single/double/triple), `segment_number`, `started_at` and
`ended_at`. "Phlebotomist" is the existing `collected_by`: the authenticated
user, never request input. `collection_datetime` is written as `ended_at` for
anything still reading it. The bag is **record only** — it does not cap the
component breakdown. No maximum draw duration is enforced; that is a clinical
constant nobody owns. All four columns are nullable in the database (legacy
rows) and required by `RecordCollectionRequest`.

**DECISION (segment uniqueness):** Unique per facility, as
`unique(facility_id, segment_number)`. `facility_id` is copied onto
`blood_collections` (backfilled from `donations`) because a unique index cannot
reach through the donation. The request gives the friendly error; the service
catches the violation — outside the transaction, since Postgres aborts it — and
returns the same error. The number is normalised (scanner control characters
and whitespace stripped, uppercased) on the counter, in the request and in the
Testing page's lookup, so a scanned and a typed copy collide.

**DECISION (two sections, each with its own author):** Immunohematology
(`donation_immunohematology`) and serology (`donation_serology`) are separate
rows, saved separately, each stamped `recorded_by`/`recorded_at` — the form's
"Screened by". Two medical technologists can split the work, in either order.

**DECISION (ABO + Rh as one blood_type_id):** The form prints two rows; the
server keeps the combined `blood_types` code every other table already uses.
The client shows two pickers and resolves them to the one row, and refuses a
combination the centre has not set up rather than guessing.

**DECISION (the panel):** Exactly five markers — HIV, HBsAg, HCV, Syphilis,
Malaria — as columns, `reactive | non_reactive`, all required. The printed
form's NAT and "Others" rows are not recorded. Each reading is final: repeat
testing happens at the bench, so there is no initial/repeat pair and no
per-marker inconclusive. `SerologyMarker` is the single definition.

**DECISION (the derived summary):** `donation_test_results` is unchanged and is
now written only by the Testing service: `passed` once both sections are in
and every marker is non-reactive (the donation moves to `tested`, handed to
Processing), or `reactive` when serology is reactive and a typing exists. The
route that let a result be entered directly (`POST …/results`) and
`RecordTestResultRequest` are removed: nothing may pass a donation without all
five readings. This supersedes the SCOPE BOUNDARY line above that "nothing in
`LaboratoryService` computes, infers or derives a result": it still never
infers a reading, but it does roll the five up by the form's own rule.

**CONSEQUENCE (legacy results):** A donation that passed under the old screen
has a summary row and no serology. `guardReadyToComplete` refuses it
(`serology_not_recorded`) until Testing records the panel, and the Testing
queue (`stage=testing`) includes it. Legacy `inconclusive` rows still display
and can still be rejected; `TestResult::Inconclusive` is never written again.

**DECISION (the reactive chain):** Any reactive marker, in the same
transaction as the reading: the donation is rejected with the fixed reason
`LaboratoryService::REACTIVE_REJECTION_REASON`, and a `counselling_referrals`
row is opened. After the commit the donor gets `DonorContactRequested`. The
server refuses a reactive panel without `confirm_reactive: true`, and the
sections are locked (`results_locked`) once a donation is rejected or
completed, so a reactive result cannot be quietly undone.

**DECISION (Testing rejects without lab.update_status):** The automatic
rejection is the one exception to the department split. It is not a
discretionary status write but the consequence of a reading only Testing may
record; leaving it for Processing would leave a reactive bag in a queue looking
like any other. Processing still rejects by hand, for anything else.

**DECISION (the deferral is derived, not tabled):** A reactive result
permanently defers the donor, and the referral row *is* that deferral.
`DonorDeferralRepository::standingDeferralFor()` reads it alongside blocking
screening outcomes and reports both the same three keys, so the counter cannot
tell a laboratory deferral from a screening one. The more severe wins, then the
more recent — a permanent deferral is no longer hidden behind a newer indefinite
one. Closing a referral does not lift the deferral. A `donor_deferrals` table
becomes worth it only when a deferral can be lifted or voided.

**DECISION (the valid-ID path shows the deferral too):** `GET /donors/lookup`
now carries `prior_deferral`, and the counter shows it. Before, a donor found by
the ID card instead of the QR code skipped the notice.

**DECISION (the privacy boundary):** Which marker was reactive appears in
exactly two places: the Testing department's own view of a donation, and the
Counselling Referrals list (`lab.referrals`, Testing only; supervisors by
`all()`). It is never in a rejection reason, an audit log context, the scan
payload, the donor history, Processing's view of the donation, or anything the
donor receives. The referral note is encrypted at rest. Every read of the list
is audited (`referral.list_viewed`).

**DECISION (referral workflow):** `pending → contacted → referred → closed`,
forward only, a step may be skipped, `closed` is final and needs a note.

**DECISION (the donor notice):** In-app only, by the project owner's decision.
"Please contact your blood centre … about your donation at {venue} on {date}",
the hotline, and no rebooking action. It says nothing of a result, a test, a
marker, an infection or a deferral: Section I-C tells the donor no official
result is issued, and that conversation belongs to counselling. `DonorDeferred`
is not used because it prints the reason.

**DECISION (a separate Testing page):** The client's Testing department works
at `/blood-center/testing` (queue, the two sections, and the referral list as a
tab). `/blood-center/laboratory` is now the Processing page, gated on
`lab.record_components`, and shows Testing's outcome read-only.

**KNOWN GAPS:** A mis-keyed reactive result cannot be undone in the app. Legacy
reactive donations are not flagged retroactively. A permanently deferred donor
can still book, as for screening deferrals.

---

## Daily Blood Stock Inventory, facility logos, and component volumes

**SOURCE:** SNBC-Mindanao's hand-kept "Daily Blood Stock Inventory as of
<date> at 8AM" sheet, signed by a medical technologist.

**DECIDED BY:** The project owner, on 2026-09-26.

**DECISION (what counts as stock):** Units that are `available` **and** not
past their expiry date, the same rule the hospital availability search uses.
A unit past its date that the nightly sweep has not reached yet is not
counted; a unit expiring today is, since it may be issued until the day
ends. Reserved, issued, expired and discarded units never count.

**DECISION (layout):** The sheet's three tables, as it draws them:
- Rh-positive Packed RBC by expiry date;
- an Rh-negative table of PRBC, FFP, Cryo, Platelet Concentrate and
  Cryosupernate (the sheet has no Rh-negative Cryosupernate column; it is added
  so that stock cannot be hidden);
- an Rh-positive table of Platelet Concentrate, FFP, Cryo and Cryosupernate.

Every other catalogue component (Whole Blood, Washed RBC, anything a centre
adds) goes in a fourth "Other components" table, always shown, zeros
included. Which component fills which place is
`config('blood_center.stock_report.roles')`. Rows follow the sheet: A, B, O,
AB within each Rh, each in its ABO colour band.

**DECISION (dated columns follow the shelf life):** A component gets one
column per expiry date when its shelf life at this facility is at or under
that facility's Packed RBC shelf life (`stock_report.reference_component`).
Red cells and platelets fall under it; frozen plasma products do not, and
show a total. When Packed RBC itself has no shelf life set, 42 days
(`dated_fallback_days`) stands in, and the report says so. A component with
no shelf life configured shows a total, flagged "Shelf life not configured".
Counts expiring today or tomorrow are boxed.

**DECISION (who prepares it):** Issuance, by `inventory.create` (supervisors
by `all()`), not the `inventory.view` every laboratory department holds. The
"BY:" line is the signed-in user's name and recorded position. Each PDF
download is audited (`inventory.stock_report_downloaded`).

**DECISION (header):** "Republic of the Philippines / Department of Health /
Davao Center for Health Development", then the facility's own name — from
`stock_report.header`, fixed to Davao for now because every named institution
sits under it. The DOH seal is bundled (`resources/images/doh-seal.png`,
supplied with the deployment) and the right-hand logo is the facility's own
upload. dompdf needs PHP's GD extension to draw either; without it the PDF
still renders, with no images, rather than failing.

**DECISION (facility logo):** Uploaded by a supervisor (`center.configure`)
for their own facility only — the facility comes from the token. PNG or JPEG,
2 MB at most; WebP is refused because dompdf cannot reliably draw it. Stored
on the private `local` disk, never the public one; served to browsers by a
30-minute signed route (`blood-center.facility.logo`), and inlined as a data
URI in PDFs because remote fetching is off. Replacing it deletes the old file
after the new path is saved.

**DECISION (catalogue):** "Platelets" is renamed "Platelet Concentrate" in
place — same row and id, so units, settings and request items are untouched —
and "Cryosupernate" is added. Migration
`2026_09_27_000001_add_cryosupernate_and_rename_platelets` does both on a
live database and does nothing to an empty one (the seeder carries the full
catalogue). `IndicationCode` P1–P6 and the Blood Request Form PDF, which look
the component up by name, were changed with it.

**DECISION (Processing records volume, per bag):** The component breakdown is
one row per bag, with its volume in mL, instead of a count per component. Two
bags of the same component are two rows, so the unique
`(donation_id, component_id)` index is gone. Each row carries `quantity` 1;
the column stays because inventory's ledger of declared bags is its sum, and
breakdowns recorded before volumes were kept still hold a real count there.
At intake each unit takes the volume of its component's next un-booked bag,
in declaration order, and keeps it in `blood_units.volume_ml`. The 1–1000 mL
bound is a typing guard, not a clinical rule; nothing compares the bags to the
collected volume.

## Walk-in requests, partial fulfilment and follow-ups

**DECISION (project owner, 2026-09-27):** There are still two request purposes — Blood Bank Replenishment and Patient Transfusion. What is new is the *source*: `blood_requests.request_source` is `blood_bank_portal` (every request before this, and every portal submission) or `blood_center_walk_in`.

- **Walk-in (D1):** a watcher who brings a patient's request straight to a blood centre. Issuance phones the hospital blood bank first; while they are on the call nothing is saved, and if the hospital does not confirm, nothing is recorded. Only a confirmed request is created (`POST /blood-center/blood-requests/walk-in`, ability `requests.record`, held by the Inventory Control Officer and Dispatch Coordinator). It is the hospital's request — `facility_id` is the hospital, it takes the next number in the hospital's `RQ-{hospital}` sequence, it appears in the hospital's own list — with `requested_by` null and `recorded_by` the centre staff member. The watcher is stored as the representative who presented it, and the hospital staff member who confirmed it (name, position, number called, time) on `blood_request_walk_ins`. None of that is written to `audit_logs`. Purpose is forced to Patient Transfusion; replenishment stays portal-only.
- **Registered hospitals only (D2):** the hospital must be an approved `blood_bank` facility.
- **Duplicates:** before the call, `POST …/walk-in/duplicates` looks for the hospital's active requests for the patient (reference number, or name and blood type within 14 days). A request already at this centre is opened instead; one part-filled elsewhere can be continued as a follow-up; anything else needs a written reason (`duplicate_acknowledgement`), re-checked under the hospital's lock on save. The portal warns the hospital the same way (`POST /hospital/blood-requests/patient-matches`).
- **Fulfilment is counted at dispatch (D3):** `RequestStatusResolver` is the one place a status is derived. Anything released makes a request Partially Fulfilled; every requested unit released makes it Fulfilled. Receipt is still stamped per unit by the hospital and reported as "received x of y", but no longer moves the status. This replaces the rule that a request was only fulfilled on receipt, and fixes a top-up knocking a partial request back to Processing. `php artisan requests:resettle` (with `--dry-run`) brings requests settled under the old rule into line.
- **Requested versus fulfilled:** a line's `quantity` is never changed. A remainder the centre cannot supply is closed with a reason (`unavailable`); one the hospital no longer needs is closed as `not_needed`. When every line is supplied, closed or forwarded, a short request stays `partial` with `closed_at` set — shown as "Partially Fulfilled (Closed)" — and can no longer be allocated. Closing every line of a request nothing was supplied on is refused: that is a rejection or a cancellation.
- **Remainder from another facility (D4):** a follow-up (`parent_request_id`, per-line `parent_item_id`) asks another centre for what is left — from the portal (`POST /hospital/blood-requests/{id}/follow-up`) or as a walk-in at that centre. What it carries is subtracted from what the original may still allocate, under the original's row lock, so a unit is never asked of two facilities at once; a follow-up that is rejected or cancelled gives its quantity back.
- **History:** every change writes one append-only `blood_request_events` row — who, from which facility, status from and to, and every line's requested / reserved / fulfilled / received / forwarded / remaining at that moment. Both portals read it (`GET …/{id}/history`).

**CONSEQUENCE:** There is still no MOA/partner relationship between a hospital and a blood centre in the schema; a request may be addressed to any approved centre. A walk-in's billing is raised against the hospital like any other request (every component is subsidised today).

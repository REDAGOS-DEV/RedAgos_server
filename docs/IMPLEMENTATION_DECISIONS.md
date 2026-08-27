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
`LaboratoryService` computes, infers or derives a result.

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

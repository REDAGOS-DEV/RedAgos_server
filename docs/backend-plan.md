# Backend Plan — Account Registration, Authentication & Donor

**Scope:** Account Registration, Authentication, Donor features.
**Explicitly out of scope:** Blood Center module, Blood Bank / Hospital module, and their dashboards. Where donor features need blood-center-owned data (facilities, drives, slots), this plan specs **donor-facing read-only endpoints only** — no Blood Center CRUD.

**Status:** Planning document. Nothing implemented.

---

## 1. Context

The Nuxt frontend (`RedAgos_client`) has a complete donor UI — 9 screens plus registration/login — but only 6 of its ~20 backend calls actually resolve. The Laravel backend (`RedAgos_server`) already implements registration, login, dashboard, profile, password and notification preferences; everything else the donor UI needs (eligibility screening, QR check-in, donation history, appointments, notifications) is called via **relative `$fetch('/api/...')` URLs that hit the Nuxt origin on :3000**, where no Nitro handler exists. Those calls 404 today and carry no `Authorization` header.

This is **not a greenfield build**. The goal of this pass is to close the gap between what the donor UI already asks for and what the API actually serves, and to move eligibility logic — currently computed client-side in `EligibilityPage.vue` and trivially forgeable — onto the server.

**Intended outcome:** every donor screen is backed by a real, authenticated, server-authoritative endpoint; eligibility and booking rules are enforced in Laravel rather than in Vue.

---

## 2. Established baseline (do not re-pick)

| Concern | What exists |
|---|---|
| Backend | **Laravel 13 / PHP 8.3**, `RedAgos_server` |
| Auth | **Laravel Sanctum** personal access tokens; client stores in `localStorage._token` |
| DB | **MySQL/MariaDB** (`redagos_db`), Eloquent |
| Layering | Controller → FormRequest → Service → Repository (`app/Http/Controllers`, `app/Http/Requests`, `app/Service`, `app/Repository`) |
| Scaffolding | `php artisan make:api-layer {Name}` generates the full stack — **use it for every new resource** |
| Schema | 20 domain tables already migrated: `facilities`, `mobile_events`, `donation_appointments`, `donations`, `blood_types`, `donor_profiles`, `roles`/`role_user`, … |
| RBAC | `role:` middleware alias → `app/Http/Middleware/RequireRole.php`, already variadic (`role:admin,donor`) |
| Frontend HTTP | `app/api/BaseService.ts` — the only correct client; already attaches the bearer token and unpacks Laravel 422 `errors` |

**No new stack decision is required.** Everything below extends this.

Already correct and reusable, do not rewrite:
- `DonorService::normalizePhilippinePhone()` — PH mobile normalisation to `+63…`
- `RegisterDonorRequest` — PH regex, `Password::min(8)->mixedCase()->numbers()`, blood-type `exists`, `terms_accepted`
- `RequireRole` middleware
- `DonorRepository` query helpers (`recentDonations`, `countCompletedDonations`, `monthlyCompletedDonationCounts`)

---

## 3. Confirmed decisions

1. **Activation gate: login open, donating gated.** A newly registered donor may log in and browse immediately. Email verification is required before **booking an appointment** or **being issued a QR token**.
2. **Three independent time rules, not one.** These are distinct concepts and must not be collapsed:
   - **56 days** — minimum whole-blood donation interval (PH/WHO standard)
   - **90 days** — validity of a preliminary eligibility screening
   - **14 days** — validity of a QR / check-in token
   Screening validity is **not** a substitute for the donation interval.
3. **Screening is preliminary.** The self-declared questionnaire produces a provisional result. **Final medical eligibility is determined by authorized blood-center personnel on site.** All copy and all API field names must reflect this.
4. **Donor-facing booking reads + full server-side gating.** Donors get read-only views of centers, drives and slots, and may create/cancel/reschedule **only their own** appointments. Eligibility, screening validity, donation interval, slot availability and donor ownership are all enforced server-side.

---

## 4. Registration and Authentication are DISTINCT flows

Confirmed from code, not assumed. Three independent pieces of evidence:

- **Separate endpoints, separate services.** `routes/api.php:11-14` — `POST /login` → `AuthController@login`; `POST /donors/register` → `DonorRegistrationController@register`. `AuthService.ts` has **no** `register()` method; registration lives on `DonorService.ts:15`.
- **Registration issues no token.** `DonorService::register()` (`app/Service/DonorService.php:29-86`) returns a 201 with the user payload and never calls `createToken`. The client redirects to the login page rather than storing a session (`app/pages/auth/donor/register.vue:270`).
- **A third, post-login onboarding stage exists** — a checklist inside the donor dashboard, not a signup wizard (`app/components/donor/DashboardPage.vue:409-440`: screening → appointment → donation → profile completion).

**Relationship:** `role-selection → role-specific register form → (redirect) login → dashboard onboarding checklist`. There is no multi-step signup wizard and no verification step between register and login today. This plan **adds** the verification step per decision 3.1, positioned as a gate on donating rather than on logging in.

They are therefore documented as two sections below.

---

# A. Account Registration

## A.1 Current state

`app/pages/auth/donor/register.vue` — fields `first_name, last_name, email, phone, blood_type, gender, birth_date, address, password, password_confirmation, terms_accepted`. **Zero client-side validation** beyond the terms checkbox gating the submit button; all rules are already enforced server-side by `RegisterDonorRequest` and surfaced through the 422 `errors` bag.

The three non-donor register pages (`blood-center`, `hospital`, `admin`) are `setTimeout` stubs with no HTTP call. **Out of scope** — this pass covers donor registration only. Note this means "registered" org accounts cannot log in at all; that gap belongs to the Blood Center / Blood Bank pass.

## A.2 Validations that exist only on the frontend

| Rule | Where it lives now | Server action |
|---|---|---|
| Terms must be accepted | button `:disabled` only | Already covered by `terms_accepted => accepted` — **but nothing persists the acceptance**. Add `terms_accepted_at` timestamp. |
| Password match | not checked client-side at all | Already covered (`confirmed`) |
| Gender enum | select offers `male/female/other` | Backend also accepts `prefer_not_to_say`; align the select |
| Age ≥ 18 at registration | not checked anywhere | **Add** `birth_date` → `before_or_equal:-18 years` |

## A.3 Endpoints

| Method | Route | Auth | Notes |
|---|---|---|---|
| `POST` | `/api/donors/register` | public, `throttle:5,1` | **Exists.** Extend: set `account_status='pending_verification'`, persist `terms_accepted_at`, dispatch verification mail. Still returns 201 with **no token**. |
| `POST` | `/api/email/verify` | public, signed + `throttle:6,1` | Body `{ id, hash }` (Laravel signed URL params). Sets `email_verified_at`, `account_status='active'`, `activated_at`. |
| `POST` | `/api/email/verification-notification` | `auth:sanctum`, `throttle:3,10` | Resend. 204 regardless of current state (no enumeration). |

**Failure cases to handle explicitly:** duplicate email → 422 `email`; duplicate phone → 422 `phone` (already implemented in `DonorService::register`); unknown blood type code → 422 `blood_type`; invalid/expired verification signature → 403; already-verified → 204 idempotent, not an error; rate limit → 429 with `Retry-After`.

## A.4 Model changes

- `users.account_status` — replace the free `string(30)` with an enum-backed cast: `pending_verification | active | suspended | deactivated`. Existing default is `pending_activation`; add a migration to rename the value.
- `users.terms_accepted_at` — new nullable timestamp.
- `User` implements `Illuminate\Contracts\Auth\MustVerifyEmail`. `email_verified_at` **already exists** in `0001_01_01_000000_create_users_table.php` — no new column needed.
- `donor_profiles.valid_id_number` is `unique` and nullable; registration never sets it. Leave as-is; it belongs to the Blood Center walk-in flow.

---

# B. Authentication

## B.1 Current state and the security holes it implies

`AuthController@login` validates **only** `email` + `password`. It performs **no role check**, so:

- The `role` field every login page sends is silently discarded → a donor can sign in through `/auth/admin/login` and be redirected to an admin URL.
- `licenseNumber`, collected on three login forms, is never validated.
- `account_status` and soft-deletion are never checked → a suspended or soft-deleted user can obtain a valid token.
- `hospital/login.vue:210` and `admin/login.vue:210` both send `role: 'blood-center'` — a frontend bug, but the server must not depend on the field being right anyway.

There is **no logout endpoint**. The client deletes `localStorage._token` in one place and forgets to in five others, so Sanctum tokens stay valid indefinitely after "logout".

Password reset is **entirely fake** — four byte-identical `setTimeout` stubs, no API, no reset page, no route.

## B.2 Endpoints

| Method | Route | Auth | Request → Response |
|---|---|---|---|
| `POST` | `/api/login` | public, `throttle:5,1` | `{ email, password, role? }` → `{ user, token, token_type, must_verify_email }`. **Extend:** reject if `account_status !== 'active'` and status is `suspended`/`deactivated` (403); reject if the user does not hold the requested `role` (403 `role_mismatch`) when `role` is supplied; name the token per role. |
| `POST` | `/api/logout` | `auth:sanctum` | `{}` → 204. `$request->user()->currentAccessToken()->delete()`. |
| `POST` | `/api/logout-all` | `auth:sanctum` | Revoke all tokens for the user. Used by "change password" and "delete account". |
| `GET` | `/api/user` | `auth:sanctum` | **Exists.** Returns the raw model with eager loads; should be moved behind a `UserResource` so `password`/internal ids are never at risk of leaking. |
| `POST` | `/api/forgot-password` | public, `throttle:3,10` | `{ email }` → **always** 200 `{ message }` regardless of whether the email exists (no account enumeration). Uses the existing `password_reset_tokens` table. |
| `POST` | `/api/reset-password` | public, `throttle:6,1` | `{ email, token, password, password_confirmation }` → 200. Enforce the same `Password::min(8)->mixedCase()->numbers()` rule as registration; **revoke all Sanctum tokens on success.** |

**Failure cases:** bad credentials → 422 (generic "These credentials do not match our records" — never distinguish unknown-email from wrong-password); suspended → 403 with a distinct code so the UI can show the right message; expired/used reset token → 422 `token`; throttle → 429.

## B.3 Cross-cutting

- **CORS is not configured.** `config/cors.php` does not exist while the SPA runs on :3000 and the API on :8000. Publish it and allow the frontend origin — required before anything works in the browser.
- **Token expiry.** `config/sanctum.php` `expiration` is null (tokens never expire). Set a finite TTL (recommend 7 days for donor tokens) and have `BaseService` treat 401 as "clear token and redirect to login".
- **`middleware/auth.ts` is an existence check only** — any non-empty string in `_token` passes, no expiry check, no server validation. It also redirects to `/login`, a route that does not exist. Server-side auth must therefore be treated as the only real gate.

---

# C. Donor

## C.1 Screen → data → endpoint map

| Screen (file) | Needs | Endpoint | Status |
|---|---|---|---|
| `DashboardPage.vue` | profile, eligibility_status, blood_type, total_donations, upcoming_appointment, recent_donations[], monthly_trend[] | `GET /donors/dashboard` | **Exists** — but `eligibility_status` is hardcoded `'pending'` (`DonorService.php:122`). Must be derived. |
| `ProfilePage.vue` / `SettingsPage.vue` | donor_code, full/first/last name, email, phone, birth_date, blood_type, address, avatar_url, notification_preferences, last_donation_date, next_eligible_date | `GET/PATCH /donors/profile`, `POST /donors/password`, `PATCH /donors/notification-preferences` | **Exist.** `next_eligible_date` currently returns `null` — must be computed. |
| `EligibilityPage.vue` | 8 questionnaire answers + vitals → provisional result, qr_token, validity | `GET /donors/eligibility/prefill`, `POST /donors/eligibility/screening` | **Missing** |
| `QrCodePage.vue` | profile card + `qr_token`, `screening_date`, `screening_valid_until`, `eligibility_status`, `upcoming_appointment` | `GET /donors/qr-code`, `POST /donors/qr-code/refresh` | **Missing** |
| `HistoryPage.vue` | `donations[]` (center_name, address, donated_on, time, blood_type, volume_ml, status) + `stats` | `GET /donors/donations` | **Missing** |
| `AppointmentsPage.vue` | centers, drives, time slots; create booking | `GET /blood-centers`, `GET /blood-drives`, `GET /time-slots`, `POST/PATCH/DELETE /donors/appointments` | **Missing** |
| `NotificationsPage.vue` | notifications[] with category + read state | `GET/PATCH /donors/notifications`, `POST .../mark-all-read` | **Missing** |
| `HelpPage.vue` | hotline, email, hours | `GET /support/contact-info` | **Missing** |
| `useAvatar.js` | multipart avatar upload | `POST /donors/avatar` | **Missing** |
| `SettingsPage.vue` "Delete account" | — | `DELETE /donors/account` | **Missing — currently deletes nothing** |

## C.2 Eligibility screening — the core of this pass

### Rules the frontend currently owns (all must move server-side)

`EligibilityPage.vue:258-270` computes the entire eligibility verdict in the browser and POSTs the answer as `result: 'eligible' | 'not_eligible'`. **The server must recompute and ignore the client's verdict** — accept it only as a stored `submitted_result` for audit/mismatch detection.

Rules to reimplement in a `EligibilityRuleEvaluator` service:

| Rule | Frontend source | Server value |
|---|---|---|
| Minimum age | `Number(vitals.age) < 18` | **18** — but derive from `donor_profiles.birth_date`, do **not** trust the self-typed `age` field |
| Minimum weight | `Number(vitals.weight) < 50` | **50 kg** |
| Donation interval | `daysSince < 90` on a **self-declared** date | **56 days**, computed from the last `donations` row with `status='completed'`, falling back to `donor_profiles.last_donation_date`. Never from user input. |
| Screening validity | banner says 90, code defaults to 14 | **90 days** |
| QR token validity | `qr_valid_days ?? 14` | **14 days**, independent of screening validity |
| 8 questionnaire flags | `disqualifyIfAnswer` per question | Move the question bank + disqualification flags to a seeded `eligibility_questions` table so wording and rules are versioned, not shipped in a `.vue` file |

Questions `gh_3` (medications) and `mh_3` (travel) carry `disqualifyIfAnswer: null` — recorded but non-deferring. Preserve that.

### Endpoints

| Method | Route | Auth | Shape |
|---|---|---|---|
| `GET` | `/api/donors/eligibility/questions` | `auth:sanctum` + `role:donor` | Versioned question bank → `{ version, sections: [{ key, title, questions: [{ code, number, text }] }] }`. Disqualification flags are **not** exposed. |
| `GET` | `/api/donors/eligibility/prefill` | `auth:sanctum` + `role:donor` | → `{ blood_type, age, last_donation_date }` — server-derived, read-only hints |
| `POST` | `/api/donors/eligibility/screening` | `auth:sanctum` + `role:donor` + `throttle:5,60` | `{ question_version, answers: [{ code, answer }], vitals: { weight } }` → `{ screening_id, result, screening_date, screening_valid_until, deferral_reasons[], qr_token?, qr_valid_until? }` |
| `GET` | `/api/donors/eligibility` | `auth:sanctum` + `role:donor` | Current status + latest screening summary |

**`result` vocabulary must be unified.** Frontend submits `eligible | not_eligible`; the dashboard renders `eligible | deferred | pending`. Standardise the server on **`eligible | deferred | pending | expired`** and update the frontend.

**Failure cases:** incomplete answer set → 422; stale `question_version` → 409 (force a re-fetch of the bank); screening submitted while an unexpired one exists → 409 unless `?force=true`; missing donor profile → 422 (already handled).

**Deferral reasons must be returned to the donor.** `HelpPage.vue:198` promises "Check the reason shown after your screening", but no reason field is ever fetched. Return machine-readable codes (`below_min_weight`, `below_min_interval`, `recent_illness`, …) plus display text.

## C.3 QR / check-in token

The QR encodes an **opaque token only** — never vitals or answers (the frontend comment at `EligibilityPage.vue:313` already asserts this; enforce it).

- Issued only when a screening result is `eligible` **and** `email_verified_at` is set (decision 3.1).
- Valid **14 days**; a donor whose screening is still valid (90 days) but whose QR expired uses `POST /donors/qr-code/refresh` to mint a new one without re-screening.
- Store `token_hash` (SHA-256), never the raw token; return the raw value once at issue time.
- Single-use on check-in, or short-window reusable — flag as open question (see §7).

| Method | Route | Auth |
|---|---|---|
| `GET` | `/api/donors/qr-code` | `auth:sanctum` + `role:donor` |
| `POST` | `/api/donors/qr-code/refresh` | `auth:sanctum` + `role:donor` + `throttle:10,60` |

## C.4 Appointments (donor-facing only)

Read endpoints project over existing tables — `facilities` (filtered to blood-center facility types), `mobile_events`, `donation_appointments`. **No Blood Center CRUD is exposed.**

| Method | Route | Auth | Notes |
|---|---|---|---|
| `GET` | `/api/blood-centers` | `auth:sanctum` | Replaces the hardcoded array at `AppointmentsPage.vue:262-266` (`subnational`/`prc`/`spmc`). Shape `{ id, name, location, hours, status }` — **`hours` and `status` have no columns yet** (see §7). |
| `GET` | `/api/blood-drives` | `auth:sanctum` | From `mobile_events` + a registered-count aggregate. `{ id, name, date, time, registered, total_slots, status }` |
| `GET` | `/api/time-slots?center_id=&date=` | `auth:sanctum` | `[{ time, available, total }]` — computed from center capacity minus booked `donation_appointments` |
| `GET` | `/api/donors/appointments` | `auth:sanctum` + `role:donor` | **New capability** — the UI has no appointment list yet, but cancel/reschedule requires one |
| `POST` | `/api/donors/appointments` | `auth:sanctum` + `role:donor` | `{ type: 'walkin'\|'mobile', center_id, drive_id, date, time_slot }` |
| `PATCH` | `/api/donors/appointments/{id}` | `auth:sanctum` + `role:donor` + policy | Reschedule |
| `DELETE` | `/api/donors/appointments/{id}` | `auth:sanctum` + `role:donor` + policy | Cancel → `status='cancelled'`, never a hard delete |

### Server-side gates on `POST` / `PATCH` (per decision 3.4)

All of these are currently unenforced or absent:

1. **Email verified** — else 403 `email_unverified`
2. **Valid, unexpired `eligible` screening** — else 403 `screening_required` / `screening_expired`
3. **56-day donation interval** — appointment date must be ≥ last completed donation + 56 days → 422 `below_min_interval`
4. **Slot still available** — re-check inside a transaction with a row lock; concurrent bookings must not oversubscribe → 409 `slot_unavailable`
5. **Drive capacity** — `registered < max_capacity` → 409 `drive_full`
6. **No overlapping active appointment** for the same donor → 409 `duplicate_appointment`
7. **Ownership** — an `AppointmentPolicy` must confirm `appointment.donor_id === auth()->id()`; IDOR here would expose another donor's booking → 403
8. **24-hour cancellation window** — `AppointmentsPage.vue:200` states the policy but nothing enforces it → 422 `cancellation_window_passed`
9. Appointment date not in the past, within a bookable horizon

Note: `AppointmentsPage.vue:422-429` shows "Appointment Confirmed!" even when the POST throws. A correct backend does not fix this — the frontend `catch` must be repaired too (§8).

## C.5 Donation history

| Method | Route | Auth | Notes |
|---|---|---|---|
| `GET` | `/api/donors/donations?status=&from=&to=&page=&per_page=` | `auth:sanctum` + `role:donor` | `{ donations: [...], stats: { total_donations, lives_impacted }, meta: { page, per_page, total } }` |

The UI has no filter controls today and relies on a documented "most-recent-first" backend contract. Ship the query params anyway — the list will grow. `volume_ml` has **no column** on `donations` (see §7). `lives_impacted` is a derived marketing figure (`total_donations × 3`) — compute server-side so it is consistent.

## C.6 Notifications

| Method | Route | Auth |
|---|---|---|
| `GET` | `/api/donors/notifications?category=&read=&page=` | `auth:sanctum` + `role:donor` |
| `PATCH` | `/api/donors/notifications/{id}` | `auth:sanctum` + policy (own only) |
| `POST` | `/api/donors/notifications/mark-all-read` | `auth:sanctum` + `role:donor` |
| `GET` | `/api/donors/notifications/unread-count` | `auth:sanctum` + `role:donor` |

Categories: `reminder | donation | screening | system`. Filtering is client-side today; move it to query params. The unread-count endpoint feeds the topbar bell, currently hardcoded to `0` (`donordashboard.vue`).

Use Laravel's `notifications` table (`php artisan notifications:table`) rather than a bespoke one, so appointment reminders and eligibility-expiry notices can be queued. Respect `donor_profiles.notification_preferences` when dispatching.

## C.7 Profile, avatar, account

| Method | Route | Auth | Notes |
|---|---|---|---|
| `POST` | `/api/donors/avatar` | `auth:sanctum` + `role:donor` | multipart, field `avatar`, ≤2 MB, `jpeg/png/webp`. Store outside the webroot; serve via a signed URL. `SettingsPage.vue` currently only makes a local `URL.createObjectURL` preview and never uploads. |
| `DELETE` | `/api/donors/account` | `auth:sanctum` + `role:donor` + password re-entry | Soft delete + anonymise PII + revoke all tokens. **Donation records must be retained** (clinical/traceability requirement) with the donor reference pseudonymised. |

**Field-name drift to fix (server should accept both, frontend should be corrected):** `ProfilePage.vue` binds `date_of_birth`/`contact_number` but PATCHes `birth_date`/`phone`; `SettingsPage.vue` sends a different payload shape to the same endpoint. `DonorService::profile()` already returns both aliases — keep that, and have `UpdateDonorProfileRequest` accept both.

**Two competing notification-preference key sets** exist (`ProfilePage` uses `donation_updates`/`blood_drive_announcements`; `SettingsPage` uses `eligibility_renewal`/`nearby_drives`/`email_updates`). `DonorService::defaultNotificationPreferences()` already unions all six — that is the canonical set; delete the non-persisting duplicate UI in `ProfilePage`.

---

## 5. New data models

Use `php artisan make:api-layer` for each.

**`eligibility_questions`** (seeded, versioned)
`id, version, section_key, code, number, text, disqualify_if_answer (nullable bool), is_active, timestamps`
Replaces the hardcoded arrays at `EligibilityPage.vue:221-233`.

**`eligibility_screenings`** 🔒
`id, donor_id → donor_profiles.donor_id, question_version, screened_at, valid_until, result (enum: eligible|deferred|pending|expired), submitted_result, computed_result, age_at_screening, weight_kg, declared_last_donation_date, deferral_reasons (json), created_at, updated_at`
Storing both `submitted_result` and `computed_result` makes client/server divergence detectable.

**`eligibility_screening_answers`** 🔒🔒
`id, screening_id, question_code, answer (bool), created_at`
Contains HIV / Hepatitis B / Hepatitis C status, surgery and transfusion history, and medication use. **Highest-sensitivity table in the system.**

**`donor_qr_tokens`** 🔒
`id, donor_id, screening_id, token_hash (sha256, unique), issued_at, expires_at, revoked_at, last_used_at, timestamps`

**Column additions to existing tables**
- `donations.volume_ml` (unsigned int, nullable) — the History UI renders it; no column exists
- `facilities.operating_hours` (string) and `facilities.is_accepting_donations` (bool) — the center picker renders `hours` and `status`
- `users.terms_accepted_at`, `users.account_status` value migration (§A.4)

**Existing models to create** (tables exist, Eloquent models do not): `Facility`, `MobileEvent`, `DonationAppointment`, `Donation`. Required before any of the above can be written idiomatically.

---

## 6. Sensitive & health-adjacent data — required handling

The Privacy page (`app/pages/legal/Privacy.vue:15-38`) already commits to specific handling; the backend must honour it.

| Field | Tier | Required controls |
|---|---|---|
| HIV / Hep B / Hep C answer (`mh_1`), surgery & transfusion history (`mh_2`), medications (`gh_3`), acute illness (`gh_2`) | **Health data** | Encrypt at rest (`encrypted` cast or MySQL column encryption); **never** returned to any donor-facing list endpoint; readable only by the owning donor and authorized blood-center staff; every read **and** write audit-logged with actor, timestamp and reason |
| `blood_type` | Health-adjacent | Owner + authorized staff. Self-declared at registration — mark it `is_verified` and only treat lab-confirmed values as authoritative |
| `eligibility_status`, `deferral_reasons` | Health-adjacent | Currently rendered in the persistent sidebar badge — acceptable for the owner, but must never leak into any cross-donor listing |
| `weight_kg`, `age`, `birth_date`, `gender` | PII / health-adjacent | Owner + authorized staff; excluded from logs |
| `qr_token` | Credential | Store hashed only; short TTL; single-use or short reuse window; revoke on deferral, screening expiry, password reset and account deletion |
| `phone`, `email`, `address`, `avatar` | PII | Standard access control; exclude from application logs; avatar served via signed URL, not a public path |
| `donor_code` / `donor_id` | Identifier | Currently leaks into the download filename `donor-qr-{donor_id}.png` — acceptable, but keep it a display code, not a database primary key |

**Cross-cutting requirements**
- **Audit log** (`audit_logs` table or `owen-it/laravel-auditing`): every create/read/update of `eligibility_screenings`, `eligibility_screening_answers`, and every QR issue/refresh/check-in.
- **API Resources everywhere.** `GET /api/user` currently returns `$request->user()->load(...)` raw. Every donor endpoint must go through a `JsonResource` so health fields are opt-in, never opt-out.
- **Never log request bodies** for `/donors/eligibility/screening` or `/donors/register`.
- **Retention:** donation records are retained after account deletion (traceability); screening answers should have a defined retention window — flagged as an open question (§7).
- **TLS required in production**; `SESSION_ENCRYPT`/`SANCTUM_STATEFUL_DOMAINS` reviewed before deploy.

---

## 7. Assumptions, ambiguities and missing information

**Flagged rather than guessed. Each needs a decision before or during implementation.**

1. **Blood center "hours" and "status"** — the UI renders `'Mon - Fri 8 AM - 3 PM'` and `'Open today'` from a hardcoded array. `facilities` has only `name` and `address`. Assumed: add `operating_hours` (string) and `is_accepting_donations` (bool). A real opening-hours model (per-weekday, holidays) may be wanted instead.
2. **Time-slot generation is undefined.** No table models slot capacity. Assumed: derive slots from facility operating hours at a fixed interval (e.g. 30 min) with a per-slot cap, minus booked appointments. If slots are meant to be authored by center staff, that is a Blood Center concern and this becomes a dependency.
3. **`volume_ml` has no column** on `donations`, yet the History UI renders it per row. Assumed a nullable int recorded by staff at collection time.
4. **QR check-in has no consumer.** The token is issued donor-side, but the scanning/redemption endpoint belongs to the Blood Center module (out of scope). Whether the token is single-use or reusable within its 14 days is therefore undecided — it affects the schema (`last_used_at` vs `used_at`).
5. **`eligibility_status: 'pending'` is hardcoded** at `DonorService.php:122`. This plan replaces it with a derivation, which will change dashboard output for any existing seeded data.
6. **Role naming mismatch.** The seeder creates `admin, donor, blood_center, blood_bank`; the frontend role-selection uses `hospital, blood-center, donor, admin`. `hospital` maps to `blood_bank`, and separators differ (`_` vs `-`). A canonical role vocabulary must be fixed before role-scoped login (§B.2) can work.
7. **Screening-answer retention period** is unspecified. Health data should not be kept indefinitely by default.
8. **`donor_profiles.valid_id_number` is `unique` but always null** at registration — MySQL permits multiple NULLs so this works today, but the intended capture point (walk-in registration by staff?) is unclear.
9. **No donor-facing blood request / urgent-need feature exists** in the frontend, despite `blood_requests` being a migrated table. Not planned here.
10. **No rewards/points/gamification** exists anywhere in the donor UI, despite `donor_incentives` being a migrated table. Not planned here.
11. **Notification delivery channel** — the preferences distinguish `email_updates` from in-app, but no mail/SMS provider is configured (`MAIL_*` keys are stock). Assumed: database notifications now, email later.

---

## 8. Frontend defects that block or undermine the backend

Not part of the backend build, but the API cannot be validated end-to-end until these are fixed. Listing them so they can be scheduled.

**Blocking**
- **`middleware/auth.ts:10` redirects to `/login`, which is not a route** — every unauthenticated visit 404s. Real paths are `/auth/<role>/login`.
- **~11 donor calls use relative `$fetch('/api/...')`** instead of `donorService`/`BaseService`, so they hit :3000 and send no auth header. Every endpoint in §C.2–C.6 must be moved onto `BaseService`. Full list: `AppointmentsPage.vue:284,325,412`, `EligibilityPage.vue:314,357`, `HistoryPage.vue:134`, `NotificationsPage.vue:174,188,203`, `QrCodePage.vue:260`, `HelpPage.vue:233`, `useAvatar.js:37`.
- **`config/cors.php` does not exist** on the server.
- **`QRCode` is undefined** — used at `QrCodePage.vue:221` and `EligibilityPage.vue:335`, but `qrcode` is not in `package.json` and never imported. QR rendering silently fails inside a `try/catch`.

**Security-relevant**
- **Logout does not log out** — `handleLogout` in `donordashboard.vue`, `ProfilePage.vue` and three sidebars clears in-memory state but leaves `localStorage._token`. Once `POST /logout` exists, all six call sites must call it and clear the token.
- Both `/blood-center/bloodrequests` and `/hospital/bloodavailability` are missing `middleware: 'auth'`.
- **"Delete account" calls no API** (`SettingsPage.vue:436`).

**Correctness**
- Post-register redirect `/auth/login/donor` → should be `/auth/donor/login` (`register.vue:270`).
- Eligibility success modal routes to `/signup/donor/MyQRCode` and `/signup/donor/Appointments` — neither exists (`EligibilityPage.vue:296,301`).
- `layout: 'auth'` referenced but no `app/layouts/auth.vue` exists.
- `/terms` and `/privacy` links → real routes are `/legal/Terms`, `/legal/Privacy`.
- Appointment confirmation modal shows on failure (`AppointmentsPage.vue:422-429`).
- Hardcoded `selectedDate = ref('2026-04-20')` and `selectedCenterId = ref('subnational')`.
- Copy must be updated to distinguish the three time rules (56 / 90 / 14 days) and to state that screening is **preliminary**, with final eligibility determined on site.

---

## 9. Suggested build order

1. **Foundations** — `config/cors.php`; Eloquent models for `Facility`, `MobileEvent`, `DonationAppointment`, `Donation`; canonical role vocabulary (§7.6); `UserResource` on `GET /user`.
2. **Auth completion** — `POST /logout`, `/logout-all`, `/forgot-password`, `/reset-password`; `account_status` + role checks on login; Sanctum token TTL.
3. **Registration + verification** — `terms_accepted_at`, `MustVerifyEmail`, verify + resend endpoints, age ≥ 18 rule.
4. **Eligibility** — question bank table + seeder, `eligibility_screenings` (+ answers, encrypted), `EligibilityRuleEvaluator`, the four eligibility endpoints, real `eligibility_status` derivation on the dashboard, `next_eligible_date` computation.
5. **QR** — `donor_qr_tokens`, issue + refresh, verification gate.
6. **Appointments** — read endpoints, then create/cancel/reschedule with all nine gates and `AppointmentPolicy`.
7. **History + notifications + avatar + delete account.**
8. **Audit logging** across screenings and QR.

Steps 1–3 unblock everything; 4–6 have a hard dependency on them.

---

## 10. Verification

**Backend (`RedAgos_server`)**

```
composer setup          # install, key:generate, migrate
php artisan migrate:fresh --seed
composer dev            # serve :8000 + queue + pail
composer test           # PHPUnit
```

There is currently **zero real test coverage** (only the two stock `ExampleTest` files). Add feature tests per endpoint group:

- **Registration:** happy path 201 + no token; duplicate email → 422 `email`; duplicate phone → 422 `phone`; weak password → 422; under-18 birth_date → 422; `terms_accepted_at` persisted; verification mail queued (`Mail::fake()`).
- **Auth:** login success shape; wrong password → 422 generic message; suspended → 403; role mismatch → 403; `throttle:5,1` → 429 on the 6th attempt; logout revokes the token (subsequent request → 401); reset-password revokes all tokens.
- **Eligibility:** server verdict **overrides** a forged `result` in the body; each of the disqualifying answers defers independently; weight 49 → deferred, 50 → eligible; last completed donation 55 days ago → deferred, 57 → eligible; screening `valid_until` = +90 days; deferral reasons returned.
- **QR:** no token issued when deferred; no token issued when email unverified; token expires at +14 days; refresh works while the screening is still valid; expired screening → refresh refused.
- **Appointments:** all nine gates from §C.4 as individual assertions; concurrent booking of the last slot → one 201 and one 409 (`DB::transaction` + `lockForUpdate`); donor A cannot cancel donor B's appointment → 403; cancel inside 24h → 422.
- **Authorization sweep:** for every donor endpoint, assert 401 unauthenticated and 403 for a non-donor role.
- **Resource leakage:** assert no screening answer field appears in `GET /donors/dashboard`, `/donors/profile`, or `/user` responses.

**End-to-end (both repos running)**

```
# server
cd RedAgos_server && composer dev        # :8000

# client
cd RedAgos_client && npm run dev         # :3000
```

Manual path, after the §8 blocking fixes: register at `/register/donor` → verify via the mailed link (or `php artisan tinker` → `$user->markEmailAsVerified()`) → log in at `/auth/donor/login` → confirm `localStorage._token` is set → dashboard loads with a real `eligibility_status` → complete `/donor/Eligibility` → QR renders on `/donor/qrcode` → book on `/donor/Appointments` → appointment appears on the dashboard → cancel it → log out and confirm the old token now returns 401.

Negative checks worth doing by hand with `curl`: POST a screening with `result: 'eligible'` and all-disqualifying answers (must come back `deferred`); book an appointment with another donor's `id` in the path (must 403); call any `/donors/*` endpoint with an admin token (must 403).

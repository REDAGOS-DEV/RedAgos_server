# RedAgos Blood Center — Operational Responsibilities

## Final Organizational Structure

RedAgos uses **five operational departments** within the Blood Center, supported by an **Administrator/Supervisor management level** (the Center Admin).

```text
                              REDAGOS
                        BLOOD CENTER PORTAL
                                 │
            ┌────────────────────┴────────────────────┐
            │                                         │
 ADMINISTRATOR / SUPERVISOR                  OPERATIONAL STAFF
 (Center Admin) — every view                          │
            ┌─────────────┬─────────────┬─────────────┼─────────────┐
            ▼             ▼             ▼             ▼             ▼
     DONOR/COLLECTION  PROCESSING    TESTING      ISSUANCE       BILLING
```

## How a staff member's access is set (the Add Staff form)

| Field | Input | What it does |
|---|---|---|
| First name, Last name, Email | Typed | The account |
| Temporary password | Typed, or **Generate a password** | Mixed case and a number, at least 8 characters |
| Title | Typed, or pick **RMT** / **RN** | A label only |
| Department | Pick one of the five | Where a custom role works |
| Role | Pick one of the department's roles, or **type a custom one** | A picked role carries exactly the permissions below. A typed role gets everything its department does (except approving corrections) |
| Privileges | Tick **Read / Write / Update / Delete** | Cap the role: only the actions of a ticked kind remain. They never add anything the role lacks |

- **Read** — view records, queues and stock.
- **Write** — record new entries: donors, screenings, collections, results, components, stock, payments.
- **Update** — change a status: check in, complete, release, approve, correct.
- **Delete** — close a donation that cannot go ahead, or discard a unit.

What each role may do is fixed in code (`app/Support/DepartmentPermissions.php`). Reference lists stay readable whatever is ticked.

---

## 1. Administrator / Supervisor (Center Admin)

**Primary responsibility:** Oversee the overall operation of the Blood Center and monitor activities across all departments.

### Operational Responsibilities

- Monitor overall Blood Center operations through the centralized dashboard.
- Manage staff accounts and assign each person a role.
- Configure component shelf life, prices and the facility logo.
- Decide correction requests that no department approver may decide — including each approver's own.

> **Note:** Administrator/Supervisor is a management level, not a department. A supervisor may also hold a role (which only decides where they land after sign-in). A supervisor holds every ability, but is bound by the same hard rules as everyone else — see *Hard rules* below.

---

## 2. Donor/Collection

**Primary responsibility:** Register donors, screen them, and draw the blood. The visit is split by who performs each part.

| Role | May | May not |
|---|---|---|
| Medical Receptionist | Register donors, verify ID, check in arrivals, open the visit's donation, schedule drives | Read the health questionnaire, vitals, deferral reasons or lab results |
| Donor Screening Physician | Read the questionnaire and full donor history (with final lab results, read-only); record the screening; accept or defer | Record lab results |
| Phlebotomist / Registered Nurse | Record the collection box (bag, segment, times, volume) | Alter the questionnaire or screening; clear a deferred donor |
| Apheresis Specialist | Everything a phlebotomist records | Same as phlebotomist |

The Donor Screening Physician is this department's **correction approver**.

---

## 3. Processing

**Primary responsibility:** Separate each bag into components and hand them to Issuance. Works **blind** — by segment number and donation number, never by donor name.

| Role | May | May not |
|---|---|---|
| Component Laboratory Medical Technologist | Record the component breakdown; complete or reject the donation | See who the donor is |
| Processing Laboratory Assistant | Record the component breakdown | Complete or reject; see who the donor is |

Processing does **not** wait for test results: completing a donation hands its bags to Issuance, which books them into **quarantine**. The Component Technologist is this department's correction approver.

---

## 4. Testing

**Primary responsibility:** Section II's two tables — the confirmatory **blood typing** and the five-marker **serology** panel (HIV, HBsAg, HCV, Syphilis, Malaria) — each recorded as a result and who screened it, on one page, **TTI Testing**.

| Role | May | May not |
|---|---|---|
| Serology / Molecular Medical Technologist | Record the typing (forward and reverse ABO grouping, Rh, antibody screen) and the serology panel | See who the donor is; undo a reactive result |
| Laboratory Supervisor | The same; approve Testing corrections; work the counselling referral list | Delete or change a reactive result |

- A typing is **cleared** when forward and reverse grouping agree and the antibody screen is negative; otherwise it is saved but *held*.
- Saving a non-reactive panel issues the unit's **TTI clearance** at once.
- A **reactive** marker acts the moment it is saved: the donation is rejected, the donor permanently deferred and referred for counselling, and any bags already booked in stay locked in quarantine.
- Which marker was reactive is visible only to this department; the referral list, which names the donor, only to the Laboratory Supervisor.

The Laboratory Supervisor is this department's correction approver.

---

## 5. Issuance

**Primary responsibility:** The shelf — booking bags in, releasing them from quarantine, and sending them out.

| Role | May | May not |
|---|---|---|
| Inventory Control Officer | Book bags in (quarantined); manage stock; **release from quarantine**; decide on and allocate against requests (for a patient) | Release a unit whose donation lacks either clearance, or was rejected |
| Dispatch / Transport Coordinator | Decline requests, return holds, release reserved units for transport | Any clinical or laboratory screen; pick units |
| IT Data Entry Clerk | Read inventory records | Change anything |

---

## 6. Billing

**Primary responsibility:** Financial transactions for fulfilled requests.

| Role | May |
|---|---|
| Billing Clerk | Raise statements, record payments and subsidies, read the requests being billed |

---

## The life of a donation

```text
COUNTER      receptionist opens ─► physician screens ─► phlebotomist draws        (collected)
                                                            │
LABORATORY   Processing separates & completes ──────────────┼──► Issuance books bags in: QUARANTINED
  (in parallel)                                             │
             Testing types the unit ─► concordant? ─► typing clearance
             Testing records the panel ─► non-reactive ─► TTI clearance
             (reactive at any point ─► donation rejected, bags locked in quarantine)
                                                            │
ISSUANCE     both clearances ─► Inventory Control Officer releases ─► AVAILABLE
             Inventory Control Officer reserves for a request ─► dispatch releases ─► ISSUED
```

---

## Hard rules

These are enforced in the services and models, not by permissions, so the Center Admin is bound by them too:

1. A reactive serology result can never be changed, deleted or corrected.
2. A unit leaves quarantine only when both clearance tokens exist and its donation was not rejected.
3. Clearance tokens are never edited or deleted. One is only ever revoked — once, and kept as history — when an approved correction replaces the result it stood on.
4. An edit to a unit never moves it out of quarantine.
5. Nothing is dispatched whose donation lacks either clearance.
6. Laboratory and inventory screens never show a donor's name to a role that does not meet donors.
7. A saved record (screening, collection box, typing, serology panel, component breakdown) is never saved over. Its writer files a **correction request**; the department's approver — or the Center Admin — decides, and nobody decides their own. A cleared result can be corrected only while none of the donation's bags has left quarantine.

---

## Scope Boundary

RedAgos does **not** perform physical screening, laboratory testing, component separation or cross-matching. Qualified healthcare professionals perform those procedures; the system records the resulting information and status.

---

## Access and UI Principle

The **Center Admin** receives the overall Blood Center view. Every other staff member receives the pages their **role and privileges** allow — each role lands on its own home page (a custom role on its department's), and the sidebar shows only what its abilities reach. The server re-checks every ability on every request; the client's navigation is presentation only.

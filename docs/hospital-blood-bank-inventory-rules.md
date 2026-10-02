# Hospital Blood Bank Inventory Rules

**Project:** RedAgos  
**Scope:** Hospital Blood Bank inventory only  
**Document type:** Business-rule specification  
**Status:** Authoritative functional reference for implementation planning

---

## 1. Purpose

This document defines the inventory and blood-unit tagging rules for **Hospital Blood Banks** in RedAgos.

These rules describe how a specific physical blood bag moves through:

- Available inventory
- Tag Assigned
- Tag Crossmatched
- Untagged Assigned
- Untagged Crossmatched
- Transfused

These rules are specific to the Hospital Blood Bank workflow and must not be automatically applied to Blood Center inventory.

---

## 2. Core Business Rule

A **Tag** is associated with a **specific physical blood unit**.

When a blood unit is Tag Assigned, the hospital blood bank has already selected a specific physical blood bag for a specific patient.

It is therefore **not** merely a reservation of a blood type, component, or quantity.

### Critical rule

> **Tag Assigned = a specific physical BloodUnit is reserved for a specific patient, remains physically inside hospital blood bank storage, and is no longer available for another patient.**

---

## 3. Terminology

### 3.1 Available

The blood unit is available for allocation to a patient.

```text
BloodUnit
├── physically in hospital storage
├── not assigned to a patient
└── available for allocation
```

---

### 3.2 Tag Assigned

A specific physical blood unit has been assigned/reserved for a specific patient, but crossmatching has not yet been completed.

```text
Available
    ↓
Tag Assigned
    ├── specific BloodUnit
    ├── specific Patient
    ├── physically remains in storage
    ├── unavailable for another patient
    └── crossmatch pending
```

The Tag Assigned state has a **24-hour crossmatch deadline**.

If crossmatching is completed within the required period, the same physical BloodUnit transitions to **Tag Crossmatched**.

If the 24-hour period expires before crossmatching is completed, the unit becomes **Untagged Assigned** and is released back to available inventory.

---

### 3.3 Tag Crossmatched

The same specific blood unit that was Tag Assigned has undergone crossmatching for the patient.

The blood bag is taken out of hospital blood bank storage and is waiting for transfusion.

```text
Tag Assigned
    ↓
Crossmatch completed
    ↓
Tag Crossmatched
    ├── same specific BloodUnit
    ├── same specific Patient
    ├── removed from storage
    ├── unavailable for another patient
    └── waiting for transfusion
```

The Tag Crossmatched state has a **24-hour transfusion deadline**.

If transfusion occurs within the required period, the unit becomes **Transfused**.

If the 24-hour period expires without transfusion, the unit becomes **Untagged Crossmatched** and is returned to available inventory according to the hospital's operational rules.

---

### 3.4 Untagged Assigned

This is the result of a Tag Assigned period expiring before crossmatching is completed.

```text
Tag Assigned
    ↓
24-hour deadline expires
    ↓
Untagged Assigned
    ↓
Release assignment
    ↓
Available
```

The system must:

- release the patient assignment;
- release the blood unit from the active allocation;
- make the unit available again;
- record the untagging event;
- preserve the previous assignment in the audit history.

The previous record must not simply be deleted.

---

### 3.5 Untagged Crossmatched

This is the result of a Tag Crossmatched period expiring before transfusion occurs.

```text
Tag Crossmatched
    ↓
24-hour deadline expires
    ↓
Untagged Crossmatched
    ↓
Release crossmatch allocation
    ↓
Available
```

The system must:

- release the patient allocation;
- return the blood unit to available inventory according to hospital rules;
- record the untagging event;
- preserve the previous crossmatch/allocation history.

The previous record must not simply be deleted.

---

### 3.6 Transfused

The blood unit has been used for the intended patient transfusion.

```text
Tag Crossmatched
    ↓
Transfusion
    ↓
Transfused
```

A transfused unit must not return to available inventory.

---

# 4. Complete Lifecycle

The primary successful lifecycle is:

```text
AVAILABLE
    │
    │ Tag Assigned
    ▼
TAG ASSIGNED
    │
    │ Crossmatch completed
    ▼
TAG CROSSMATCHED
    │
    │ Transfused
    ▼
TRANSFUSED
```

### Tag Assigned expiration path

```text
TAG ASSIGNED
    │
    │ 24h expires without crossmatch
    ▼
UNTAGGED ASSIGNED
    │
    ▼
AVAILABLE
```

### Tag Crossmatched expiration path

```text
TAG CROSSMATCHED
    │
    │ 24h expires without transfusion
    ▼
UNTAGGED CROSSMATCHED
    │
    ▼
AVAILABLE
```

Combined:

```text
                         ┌──────────────────┐
                         │    AVAILABLE     │
                         └────────┬─────────┘
                                  │
                            Tag Assigned
                                  ▼
                         ┌──────────────────┐
                         │   TAG ASSIGNED   │
                         │                  │
                         │ Crossmatch       │
                         │ pending          │
                         └───────┬──────────┘
                                 │
                  ┌──────────────┴──────────────┐
                  │                             │
       Crossmatch completed             24h expires
                  │                             │
                  ▼                             ▼
       ┌──────────────────┐          ┌────────────────────┐
       │ TAG CROSSMATCHED │          │ UNTAGGED ASSIGNED  │
       │                  │          └─────────┬──────────┘
       │ Waiting for      │                    │
       │ transfusion      │                    │
       └────────┬─────────┘                    │
                │                              │
       ┌────────┴────────┐                     │
       │                 │                     │
  Transfused       24h expires                 │
       │                 │                     │
       ▼                 ▼                     │
┌─────────────┐  ┌──────────────────────┐      │
│ TRANSFUSED  │  │UNTAGGED CROSSMATCHED │      │
└─────────────┘  └──────────┬───────────┘      │
                             │                  │
                             └────────┬─────────┘
                                      ▼
                               ┌─────────────┐
                               │  AVAILABLE  │
                               └─────────────┘
```

---

# 5. State Behavior

| State | Specific BloodUnit | Patient-specific | Physically in storage | Available to another patient |
|---|---|---|---|---|
| Available | Yes | No | Yes | Yes |
| Tag Assigned | Yes | Yes | Yes | No |
| Tag Crossmatched | Yes | Yes | No | No |
| Untagged Assigned | Previously assigned | Released | Yes | Yes |
| Untagged Crossmatched | Previously crossmatched | Released | Returned | Yes |
| Transfused | Yes | Yes | No | No |

**Note:** The exact inventory counting/display behavior for each state must follow the hospital blood bank's operational definition and the existing RedAgos inventory architecture.

---

# 6. 24-Hour Rules

There are two distinct 24-hour operational periods.

## 6.1 Tag Assigned → Crossmatch Deadline

When a blood unit enters Tag Assigned:

```text
assigned_at = timestamp when Tag Assigned occurs
crossmatch_deadline = assigned_at + 24 hours
```

Example:

```text
Tag Assigned:
October 2, 08:00

Crossmatch deadline:
October 3, 08:00
```

If crossmatching is completed before the deadline:

```text
TAG ASSIGNED
    ↓
TAG CROSSMATCHED
```

If the deadline expires without crossmatching:

```text
TAG ASSIGNED
    ↓
UNTAGGED ASSIGNED
    ↓
AVAILABLE
```

---

## 6.2 Tag Crossmatched → Transfusion Deadline

When the blood unit enters Tag Crossmatched:

```text
crossmatched_at = timestamp when Tag Crossmatched occurs
transfusion_deadline = crossmatched_at + 24 hours
```

Example:

```text
Crossmatched:
October 3, 10:00

Transfusion deadline:
October 4, 10:00
```

If transfused before the deadline:

```text
TAG CROSSMATCHED
    ↓
TRANSFUSED
```

If the deadline expires without transfusion:

```text
TAG CROSSMATCHED
    ↓
UNTAGGED CROSSMATCHED
    ↓
AVAILABLE
```

---

# 7. Physical BloodUnit Tracking

The implementation must track the **actual physical BloodUnit** throughout the lifecycle.

The system should not model Tag Assigned as merely:

```text
O+ PRBC: -1 available
```

without identifying which physical blood bag was assigned.

The allocation should be associated with the actual BloodUnit and patient.

Conceptually:

```text
Patient
   │
   │ assigned to
   ▼
BloodUnit
   │
   └── Tag / Allocation record
          ├── patient
          ├── blood unit
          ├── tag type
          ├── tagged_at
          ├── deadline
          ├── status
          └── audit information
```

The same physical BloodUnit should transition from:

```text
AVAILABLE
    ↓
TAG ASSIGNED
    ↓
TAG CROSSMATCHED
    ↓
TRANSFUSED
```

or return through an untagging path:

```text
TAG ASSIGNED
    ↓
UNTAGGED ASSIGNED
    ↓
AVAILABLE
```

or:

```text
TAG CROSSMATCHED
    ↓
UNTAGGED CROSSMATCHED
    ↓
AVAILABLE
```

---

# 8. Inventory Rules

The Hospital Blood Bank inventory must distinguish between:

1. **Physical blood stock**
2. **Blood-unit allocation state**
3. **Patient association**
4. **Crossmatch state**
5. **Transfusion state**

Do not unnecessarily create separate physical inventory records for:

- Tagged Assigned
- Untagged Assigned
- Tagged Crossmatched
- Untagged Crossmatched

These should be represented through appropriate status/state/allocation information associated with the physical BloodUnit.

The existing RedAgos architecture must be inspected before deciding the exact implementation.

---

# 9. Automatic Expiration and Untagging

The system must support detection of expired 24-hour periods.

## 9.1 Tag Assigned expiration

Conceptually:

```text
IF
    state = TAG_ASSIGNED
AND
    current_time >= crossmatch_deadline
AND
    crossmatch has not been completed
THEN
    mark allocation as UNTAGGED_ASSIGNED
    release patient allocation
    make BloodUnit available
    record untagged_at
    preserve audit history
```

## 9.2 Tag Crossmatched expiration

Conceptually:

```text
IF
    state = TAG_CROSSMATCHED
AND
    current_time >= transfusion_deadline
AND
    transfusion has not occurred
THEN
    mark allocation as UNTAGGED_CROSSMATCHED
    release patient allocation
    return BloodUnit to available inventory
    record untagged_at
    preserve audit history
```

The implementation mechanism may use the existing application's scheduling/background infrastructure.

Do not introduce a new infrastructure mechanism until the existing project architecture has been inspected.

---

# 10. Auditability

Untagging must **not delete historical information**.

The system should preserve:

- BloodUnit identifier
- Patient identifier
- Previous state
- New state
- Tag timestamp
- Crossmatch timestamp, if applicable
- Deadline
- Untagging timestamp
- Transfusion timestamp, if applicable
- User/staff responsible for relevant manual actions
- Reason/event that caused the state transition

Example:

```text
BloodUnit: BU-00123
Patient: Patient A
Previous state: TAG_ASSIGNED
Deadline: Oct 3, 08:00
New state: UNTAGGED_ASSIGNED
Untagged at: Oct 3, 08:01
Reason: Crossmatch deadline expired
```

---

# 11. Hospital Blood Bank vs. Blood Center

These rules apply to **Hospital Blood Bank inventory**.

Do not assume that the Hospital Blood Bank lifecycle is identical to the Blood Center lifecycle.

### Hospital Blood Bank

```text
Available
    ↓
Tag Assigned
    ↓
Tag Crossmatched
    ↓
Transfused
```

with expiration paths back to Available.

### Blood Center

The existing Blood Center workflow may instead involve:

```text
Available
    ↓
Allocated / Reserved
    ↓
Dispatched
    ↓
Received by Hospital
```

The agent must inspect the current implementation before modifying shared inventory logic.

A change intended for Hospital Blood Bank must not unintentionally change Blood Center behavior.

---

# 12. FEFO and Existing Inventory Logic

Existing RedAgos inventory rules such as **First Expiry, First Out (FEFO)** should be preserved where applicable.

Tagging a specific BloodUnit must not bypass existing blood-unit expiration tracking.

When a unit is released from an untagged state and becomes available again, its original blood-unit expiration information must remain intact.

Do not reset or modify the physical blood unit's expiration date merely because it was tagged or untagged.

---

# 13. UI Requirements

The UI should make the distinction between these states clear.

Hospital Blood Bank personnel should be able to identify:

- Available units
- Tag Assigned units
- Tag Crossmatched units
- Untagged Assigned events
- Untagged Crossmatched events
- Transfused units
- Patient associated with an active tag
- Remaining time/deadline for active tags where appropriate

The interface should not make **Tag Assigned** appear identical to **Tag Crossmatched**, because they represent different operational stages.

Suggested labels:

```text
TAG ASSIGNED
Crossmatch pending
Deadline: 18h 32m
```

```text
TAG CROSSMATCHED
Awaiting transfusion
Deadline: 11h 08m
```

For expired records:

```text
UNTAGGED ASSIGNED
Crossmatch deadline expired
```

```text
UNTAGGED CROSSMATCHED
Transfusion deadline expired
```

The exact UI design should follow the existing RedAgos design system.

---

# 14. Validation Rules

The implementation should prevent invalid state transitions.

### Valid transitions

```text
AVAILABLE
    → TAG ASSIGNED

TAG ASSIGNED
    → TAG CROSSMATCHED
    → UNTAGGED ASSIGNED

TAG CROSSMATCHED
    → TRANSFUSED
    → UNTAGGED CROSSMATCHED

UNTAGGED ASSIGNED
    → AVAILABLE

UNTAGGED CROSSMATCHED
    → AVAILABLE
```

### Invalid examples

The system should prevent or reject transitions such as:

```text
AVAILABLE
    → TRANSFUSED
```

```text
TAG ASSIGNED
    → TRANSFUSED
```

without the required crossmatching step.

```text
UNTAGGED ASSIGNED
    → TRANSFUSED
```

without a new valid assignment/crossmatch lifecycle.

```text
TRANSFUSED
    → AVAILABLE
```

A transfused blood unit must not be reused as available inventory.

---

# 15. Implementation Guidance for AI Agents

Before making changes:

1. Inspect the current codebase.
2. Identify the existing `BloodUnit` model/entity.
3. Identify existing inventory tables/models.
4. Identify blood request models.
5. Identify patient/request relationships.
6. Identify current reservation/allocation logic.
7. Identify crossmatch-related logic.
8. Identify transfusion/issuance logic.
9. Identify inventory status enums/constants.
10. Identify existing expiration/background-job mechanisms.
11. Identify Hospital Blood Bank-specific UI.
12. Identify Blood Center-specific inventory behavior.

Then compare the implementation against this specification.

Do **not** immediately create new tables, enums, or APIs if equivalent structures already exist.

Prefer extending existing domain models and workflows when doing so preserves the existing architecture.

---

# 16. Required Planning Output Before Implementation

The AI agent must first produce:

## ANALYZE

Explain:

- Current inventory architecture
- Current BloodUnit lifecycle
- Existing statuses
- Existing allocation/reservation logic
- Existing crossmatch logic
- Existing transfusion/issuance logic
- Where Hospital Blood Bank tagging fits
- What needs to change
- Potential conflicts with Blood Center inventory

## PLAN

Provide:

- Database changes
- Domain/model changes
- Backend changes
- API changes
- State-transition logic
- 24-hour expiration mechanism
- Automatic untagging behavior
- Frontend changes
- Audit logging
- Validation rules
- Testing strategy
- Migration/backward-compatibility considerations

## MERMAID

Provide the proposed Hospital Blood Bank BloodUnit lifecycle diagram.

## ASCII FLOW

Provide the operational flow from Tag Assigned through Transfusion/Untagging.

## CONFIDENCE

Provide:

- confidence level
- assumptions
- unresolved implementation questions
- any business rules that still require confirmation

**Do not implement until the plan has been reviewed and approved.**

---

# 17. Non-Negotiable Business Rules

The following rules must not be changed during implementation without explicit approval:

1. **Tag Assigned refers to a specific physical blood bag.**
2. **Tag Assigned is patient-specific.**
3. A Tag Assigned blood bag remains physically inside hospital storage.
4. A Tag Assigned blood bag is not available for another patient.
5. Tag Assigned has a 24-hour crossmatch period.
6. Failure to crossmatch within that period results in Untagged Assigned.
7. Untagged Assigned releases the blood unit back to available inventory.
8. The same physical BloodUnit moves from Tag Assigned to Tag Crossmatched when crossmatching is completed.
9. Tag Crossmatched means the blood bag has been removed from storage and is awaiting transfusion.
10. Tag Crossmatched has a 24-hour transfusion period.
11. Failure to transfuse within that period results in Untagged Crossmatched.
12. Untagged Crossmatched releases the blood unit back to available inventory according to hospital operational rules.
13. A transfused blood unit cannot return to available inventory.
14. Untagging must preserve historical/audit information.
15. Hospital Blood Bank inventory rules must not unintentionally alter Blood Center inventory behavior.
16. Existing BloodUnit expiration dates must be preserved.
17. Do not implement ambiguous business rules by guessing; flag them for confirmation.

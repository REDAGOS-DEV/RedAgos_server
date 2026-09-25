# RedAgos Blood Center — Operational Responsibilities

## Final Organizational Structure

RedAgos uses **five operational departments** within the Blood Center, supported by an **Administrator/Supervisor management level**.

```text
                         REDAGOS
                  BLOOD CENTER PORTAL
                           │
          ┌────────────────┴────────────────┐
          │                                 │
 ADMINISTRATOR / SUPERVISOR          OPERATIONAL STAFF
 Overall Blood Center View                    │
                                              │
     ┌──────────────┬──────────────┬──────────┴───┬──────────────┐
     │              │              │              │              │
     ▼              ▼              ▼              ▼              ▼
 COLLECTION      TESTING      PROCESSING      ISSUANCE       BILLING /
                                                              PAYMENT
```

---

## 1. Administrator / Supervisor

**Primary responsibility:** Oversee the overall operation of the Blood Center and monitor activities across all departments.

### Operational Responsibilities

- Monitor overall Blood Center operations through the centralized dashboard.
- Monitor blood inventory, donation activities, hospital requests, fulfillment, and financial activities.
- Manage and review staff accounts and department access.
- Assign appropriate roles and permissions to authorized personnel.
- Monitor operational reports and performance indicators.
- Review system records and transactions for accountability and traceability.
- Oversee system configuration and Blood Center-related settings.
- Generate and review consolidated operational reports.

> **Note:** Administrator/Supervisor is a management/access level, not one of the five operational departments.

---

## 2. Collection Department

**Primary responsibility:** Manage donor-related activities and blood collection operations.

### Operational Responsibilities

- Register and maintain donor information.
- Manage donor profiles and donation history.
- Manage donor appointments and appointment information.
- Verify donor appointment or QR-based confirmation.
- Record donor eligibility and screening results.
- Manage donor queues and donation schedules.
- Record completed blood donations.
- Manage and monitor mobile blood donation drives.
- Schedule, reschedule, and manage donation drives.
- Maintain accurate donor and collection records.

---

## 3. Testing Department

**Primary responsibility:** Record the screening results and blood type of each collected unit.

### Operational Responsibilities

- Receive blood collection information for testing.
- Record blood screening and laboratory results provided by authorized personnel.
- Record the blood type the laboratory determined.
- Validate and document testing-related results.

### Scope Boundary

RedAgos does **not** perform the actual physical laboratory testing or cross-matching. Qualified healthcare professionals perform those procedures, while the system records the resulting information and status.

---

## 4. Processing Department

**Primary responsibility:** Record how each tested unit was processed and decide whether it may be issued.

### Operational Responsibilities

- Receive blood collection information for processing.
- Record blood component information (what each unit was separated into).
- Record and update blood processing status.
- Maintain processing records associated with collected blood.
- Update blood status when processing requirements have been completed: clear the unit for issue, or reject it.
- Provide validated blood information for subsequent inventory management.

### Scope Boundary

RedAgos does **not** perform the physical component separation. Qualified healthcare professionals perform it, while the system records the resulting information and status.

---

## 5. Issuance Department

**Primary responsibility:** Manage blood inventory and coordinate the fulfillment and release of blood requests.

### Operational Responsibilities

- Record newly collected or received blood units.
- Monitor blood inventory by blood type and component.
- Record blood unit expiration dates and storage locations.
- Monitor current blood stock levels.
- Apply FEFO (First Expiry, First Out) prioritization.
- Monitor minimum inventory thresholds and low-stock conditions.
- Check blood availability for hospital requests.
- Receive and process incoming blood requests.
- Approve or reject blood requests according to authorized procedures.
- Reserve and allocate available blood units for approved requests.
- Coordinate request fulfillment.
- Prepare and release approved blood units.
- Update inventory following reservation and release.
- Track blood request and fulfillment status.
- Maintain inventory and fulfillment records.

---

## 6. Billing / Payment Department

**Primary responsibility:** Manage financial transactions associated with blood request fulfillment.

### Operational Responsibilities

- Generate billing statements for applicable blood service fees.
- Calculate and document applicable charges.
- Record cash payments.
- Record electronic payments such as GCash.
- Verify payment status.
- Generate and maintain payment receipts.
- Maintain financial transaction records.
- Coordinate payment confirmation with the fulfillment/release process.
- Monitor pending and completed payments.
- Generate billing and payment reports.

---

## Access and UI Principle

The **Administrator/Supervisor** receives the overall Blood Center view, while each of the five operational departments receives a **department-specific dashboard and navigation**.

Department permissions should determine what staff members can:

- View
- Create
- Update
- Approve
- Release

This allows RedAgos to maintain department-based access while still allowing authorized personnel to view information needed for cross-department operations.

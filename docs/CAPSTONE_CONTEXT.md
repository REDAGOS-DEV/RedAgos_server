# RedAgos Capstone Context

**Source:** `docs/RedAgos-Capstone-1.pdf` (May 2026 proposal). Statements marked **REQUIREMENT** are supported by that paper. **IMPLEMENTATION DECISION** statements are development choices recorded separately in `IMPLEMENTATION_DECISIONS.md`. **ASSUMPTION / GAP** items are not resolved by the paper.

## 1. Project Title

- **REQUIREMENT:** *RedAgos: A Web-based Blood Bank Management and Inventory System.*

## 2. System Purpose

- **REQUIREMENT:** Provide a centralized, interoperable-ready web platform for blood donors, hospital blood banks, and blood centers in Davao City; replace fragmented/manual handling of blood requests, documents, inventory, billing, and reporting.
- **REQUIREMENT:** Use Nuxt.js, Laravel, and MySQL in a client-server architecture accessible through internet-connected web browsers.

## 3. Scope and Limitations

- **REQUIREMENT:** Scope is Academic Year 2025–2026 and participating institutions in Davao City: Southern Philippines Medical Center, the Sub-National Blood Center, and the Philippine Red Cross Davao Chapter.
- **REQUIREMENT:** The platform is web-only, requires a stable internet connection, and does not support offline transactions/synchronization.
- **REQUIREMENT:** It records authorized status updates/results but does not perform physical screening, laboratory testing, blood extraction, or cross-matching.
- **REQUIREMENT:** It is interoperable-ready only; it does not currently integrate with national health/DOH systems, external hardware, predictive modeling, or AI-based forecasting. Authorized personnel enter data manually.

## 4. User Types

- **REQUIREMENT:** The four primary user types are blood donors, hospital blood bank personnel, blood center staff, and system administrators.
- **REQUIREMENT:** Role-based access control governs authorized operations. Administrators oversee account management, facility registration, role assignment, and overall configuration.
- **IMPLEMENTATION EVIDENCE:** The current API role identifiers are `donor`, `blood_bank`, `blood_center`, and `admin`; the client labels `blood_bank` as “hospital.”

## 5. Facility Types

- **REQUIREMENT:** The proposed facility types are Blood Center and Hospital Blood Bank.
- **IMPLEMENTATION EVIDENCE:** The current schema normalizes types in `facility_types` rather than using the paper’s `facility_type` enum.

## 6. Core Modules

- **REQUIREMENT:** Blood Request and Fulfillment — hospital staff submit/monitor/track requests; blood-center staff manage incoming requests and fulfillment.
- **REQUIREMENT:** Blood Donation Management — donor registration/profile/history, preliminary screening, appointments, QR appointment confirmations, and mobile donation-drive scheduling/management.
- **REQUIREMENT:** Blood Inventory Monitoring and Threshold Management — real-time inventory visibility, FEFO prioritization, thresholds, low-stock alerts, reports, and summaries.
- **REQUIREMENT:** Billing and Payment Processing — billing after fulfillment, cash and GCash payment handling, statements/receipts, and traceable financial records.
- **REQUIREMENT:** Demand Forecasting and Analytical Reporting — usage, inventory-movement, donation-performance, and demand-trend reports/projections from historical data.

## 7. Major Business Workflows

- **REQUIREMENT:** A donor completes preliminary screening; a deferred donor is notified, otherwise the donor selects a blood center or mobile drive, books a schedule, receives a QR confirmation, and receives reminders.
- **REQUIREMENT:** Donation supports appointments and walk-ins; staff verify the donor/ID or QR code, record screening/examination outcome, and record blood data after collection.
- **REQUIREMENT:** Hospital personnel search availability by blood type/component/facility, create and submit a digital request containing patient information, blood type/component, quantity, urgency, and receiving facility; the receiving facility is notified.
- **REQUIREMENT:** Blood-service staff view incoming requests, verify availability, allocate/reserve units without exceeding available/requested quantity, then approve or reject with a recorded reason and requester notification. Request tracking includes Submitted, Processing, Approved, and Rejected states.
- **REQUIREMENT:** Inventory is viewed by blood type, component, storage location, expiry date, and status; authorized personnel record/manage units, define thresholds, receive low-stock alerts, and generate reports/summaries. FEFO prioritizes older units for issue.
- **REQUIREMENT:** Confirmed availability leads to billing. Cash requires staff confirmation; GCash uses an external payment gateway. A receipt and release authorization follow confirmed payment; no unit is released without confirmed payment.
- **REQUIREMENT:** Authorized personnel generate request, inventory, donation, financial, and analytical reports, including historical-data demand projections and graphical visualization.

## 8. Database Concepts

- **REQUIREMENT:** The paper’s data dictionary defines user/role/user-role; facility; blood component; donor profile/incentive; mobile event; appointment; donation; blood collection; blood unit; blood request; request allocation; billing; and payment concepts.
- **REQUIREMENT:** A blood unit is individually identified and records facility, component, blood type, donation source, expiry date, and status. The paper’s states are Available, Reserved, Issued, Expired, and Discarded.
- **REQUIREMENT:** A request has a requesting facility, blood type/component, quantity, urgency, status, and request date. An allocation associates a request with a blood unit and is unique per unit allocation.
- **REQUIREMENT:** Billing is associated with a request; payment is associated with billing. Billing status includes Unpaid, Partial, and Paid; payment methods are Cash and GCash.

## 9. Security and Access Control

- **REQUIREMENT:** Role-based access control, secure communication, data accuracy, reliability, usability, and security are non-functional requirements.
- **REQUIREMENT:** Only authorized users/personnel may perform role-specific request processing, status updates, inventory operations, and analytical/reporting operations.
- **ASSUMPTION / GAP:** The paper does not specify a staff-to-facility ownership model, facility-level query-isolation mechanics, organization approval lifecycle, or email-verification policy.

## 10. Important Business Rules

- **REQUIREMENT:** Preliminary screening is validated before booking; a deferred donor does not proceed to facility/drive selection.
- **REQUIREMENT:** Allocation cannot exceed available stock or requested quantity; approved units are reserved/deducted from inventory.
- **REQUIREMENT:** A rejected request records its reason and notifies the requester.
- **REQUIREMENT:** Low-stock alerts are triggered when stock falls below a defined threshold.
- **REQUIREMENT:** Use FEFO to issue older units before newer units.
- **REQUIREMENT:** Do not release blood units without confirmed payment.

## 11. Requirements That Affect Implementation

- **REQUIREMENT:** Preserve unit-level traceability, request/allocation tracking, role-based authorization, facility-aware inventory/request coordination, and the dependency of release on payment confirmation.
- **REQUIREMENT:** Support patient information in blood requests, document storage/retrieval for donation-related documents, notification handling, and the five named modules.
- **IMPLEMENTATION DECISION:** Use `users.facility_id` for server-side staff isolation and derive summaries from authoritative `blood_units`; see `IMPLEMENTATION_DECISIONS.md`.

## 12. Known Ambiguities or Gaps

- **ASSUMPTION / GAP:** “Authorized personnel,” “designated personnel,” and exact role-to-action permissions are not enumerated.
- **ASSUMPTION / GAP:** Facility staff ownership, cross-facility access, approval/onboarding, and organization-registration workflow are not specified.
- **ASSUMPTION / GAP:** The paper’s inventory storyboard allows units that are “newly collected or received,” while the current development decision permits only donation-derived units. This conflict requires an explicit requirement decision before inventory implementation.
- **ASSUMPTION / GAP:** The paper specifies demand projections but also says predictive modeling/AI forecasting is excluded; the acceptable non-AI forecasting method and outputs are not specified.
- **ASSUMPTION / GAP:** The paper gives request and unit states but does not define all valid transitions, partial-fulfillment behavior, allocation release/reallocation, or payment-failure recovery.
- **IMPLEMENTATION EVIDENCE:** Current tables/routes do not yet implement facility staff linkage, organization approval, inventory/requests/allocation/billing/payment APIs, document management, thresholds, or forecasting.

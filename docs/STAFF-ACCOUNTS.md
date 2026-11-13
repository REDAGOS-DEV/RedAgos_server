# RedAgos Staff Accounts by Facility

Every staff account in the local demo data, facility by facility: who holds it, what their role lets them do, and how they sign in. The data was reset on 2026-11-13 (three blood centres, two hospital blood banks). The step-by-step process these accounts carry out is in [`OPERATIONAL-LIFECYCLE.md`](OPERATIONAL-LIFECYCLE.md).

These accounts exist only in the demo data. On any other deployment, use the real account for each role. If this file and the database disagree, the database is right; please correct this file.

## Signing in

- **Where.** Staff of both portals sign in at `/auth/login` with the username or the email. Super Admins sign in at `/auth/admin/login`.
- **Password.** Every staff account signs in with the demo password `Password123`, so a demo can move between accounts without setting new passwords. Nobody is asked to change it at sign-in.
- **Before real use.** Anyone who reads this file can sign in as any of these accounts. Give each account its own password before the data is used anywhere outside a demo.
- **Contacts reach no one.** Emails are `@example.com` and phone numbers are in the `0900` block. RedAgos sends no email or text to either, so nobody real is ever contacted.
- **Usernames.** Blood centre accounts are `<centre>-<role>` (`snbc-billing-clerk`); hospital accounts are `<hospital>-<role>` (`spmch-head`, `ddh-inventory`). The email is the username with dots, `@example.com`.

## The facilities

| Facility | Type | Abbreviation | Accounts | Address |
|---|---|---|---|---|
| [Sub-National Blood Center](#sub-national-blood-center) | Blood centre | `SNBC` | 16 | Davao City |
| [PRC Davao Blood Services](#prc-davao-blood-services) | Blood centre | `PRC` | 16 | Davao City |
| [SPMC Blood Bank](#spmc-blood-bank) | Blood centre | `SPMC` | 16 | Davao City |
| [SPMC Hospital Blood Bank](#spmc-hospital-blood-bank) | Hospital blood bank | — | 3 | J.P. Laurel Ave, Davao City, Philippines |
| [Davao Doctors Hospital Blood Bank](#davao-doctors-hospital-blood-bank) | Hospital blood bank | — | 3 | E. Quirino Ave, Davao City |

A blood centre's abbreviation starts every bag number it books: `SNBC-21627990-PRBC` is a Packed RBC bag from SNBC donation sticker 21627990.

## What each role does

The wording below is the system's own (`app/Enums`), the same text staff see when an account is set up.

### Blood centre: management levels

A level is not a department post. It sits above, or instead of, a staff role.

| Level | What it does |
|---|---|
| **Blood Center Admin** | Creates staff accounts, assigns roles and privileges, and sets the centre's logo. Sees the dashboard, the reports and the audit log. No department pages and no corrections. |
| **Supervisor** | Sees the dashboard, the reports and every department's monitoring pages: stock, thresholds, the daily stock report, incoming requests and statements. Cannot record, approve, release or manage anyone. |

### Blood centre: staff roles

Each role belongs to one department. A person may hold several roles, all in the same department.

| Department | Role | What it does |
|---|---|---|
| Donor/Collection | **Donor Screening Physician** | Reviews health history and the questionnaire, examines the donor, and accepts or defers. Reads final lab results but cannot change them. |
| Donor/Collection | **Phlebotomist / Registered Nurse** | Records the collection: bag, donation barcode, times and adverse reactions. Cannot alter the questionnaire or clear a deferred donor. |
| Donor/Collection | **Apheresis Specialist** | Everything a phlebotomist records, plus apheresis procedures. |
| Donor/Collection | **Donor Care / Medical Receptionist** | Registers donors, verifies ID, checks in arrivals, books appointments and schedules drives. No medical history, deferral reasons or lab results. |
| Processing | **Component Laboratory Medical Technologist** | Separates whole blood into components and completes or rejects the donation. Works by barcode, blind to donor identity. |
| Processing | **Processing Laboratory Assistant** | Declares bags and volumes and prepares labels. Cannot complete or release a donation. |
| Testing | **Serology / Molecular Medical Technologist** | Records the TTI panel and the ABO/Rh typing by barcode, blind to donor identity. Cannot undo a reactive result. |
| Testing | **Laboratory Supervisor** | Records and oversees testing by barcode, blind to donor identity, verifies reactive results, approves Testing corrections and refers reactive donors to a doctor. Cannot delete a reactive result. |
| Issuance | **Inventory Control Officer** | Books units in, manages stock, allocates to requests, and releases units from quarantine once testing has cleared them. Edits a unit directly and approves Issuance corrections. |
| Issuance | **Dispatch / Transport Coordinator** | Handles hospital orders and releases units for transport, and requests a correction to a dispatch record. No clinical or laboratory access. |
| Issuance | **IT Data Entry Clerk** | Audits inventory records against scanned barcodes and requests a correction to a unit's storage location or expiry date. Cannot alter medical data, results or patient links. |
| Billing | **Billing Clerk** | Raises bills against fulfilled requests, records payments, and requests a correction to a recorded payment. |
| Billing | **Billing Supervisor** | Everything a billing clerk does, and approves Billing corrections to a recorded payment. Cannot alter inventory or clinical records. |

### Hospital blood bank roles

| Role | What it does |
|---|---|
| **Blood Bank Head** | Everything in the hospital portal, and the blood bank's staff accounts. |
| **Inventory Staff** | Stocks bags in, tags, crossmatches, transfuses, returns and discards them, receives blood from outside RedAgos, and sets stock thresholds. |
| **Requesting Staff** | Requests blood, keeps the request days, confirms dispatched blood arrived, and reads billing documents. |

## Sub-National Blood Center

A blood centre: it collects, tests, processes and issues blood. Abbreviation **`SNBC`**, so its bags are numbered `SNBC-<barcode>-<component>`.

- **Address:** Davao City · open Mon - Fri 8 AM - 3 PM
- **Primary account:** Teresa Aquino (`snbc-supervisor`)
- **Settings:** shelf life and price for every component (below), 13 deferral codes (R1, R2-Low, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R2-High), and 56 stock thresholds (one per component and blood type). Its logo and seal are uploaded.
- **Demo stock:** 2,800 available units, 50 of every component in every blood type.

| Component | Code | Shelf life | Price |
|---|---|---|---|
| Whole Blood | `WB` | 35 days | ₱1,500.00 |
| Packed RBC | `PRBC` | 42 days | ₱2,000.00 |
| Fresh Frozen Plasma | `FFP` | 365 days | ₱1,000.00 |
| Platelet Concentrate | `PC` | 5 days | ₱1,250.00 |
| Cryoprecipitate | `CRYO` | 365 days | ₱1,000.00 |
| Washed RBC | `WRBC` | 28 days | ₱999.00 |
| Cryosupernate | `CSP` | 365 days | ₱1,500.00 |

### Staff (16)

| Name | Department | Role | Employee ID | Username | Email | Phone |
|---|---|---|---|---|---|---|
| Eduardo Ramos | — | **Blood Center Admin** | ADM-001 | `snbc-center-admin` | `snbc.center.admin@example.com` | 0900 000 0115 |
| Teresa Aquino | — | **Supervisor** | SUP-001 | `snbc-supervisor` | `snbc.supervisor@example.com` | 0900 000 0101 |
| Elena Santos | — | None¹ | CNS-001 | `snbc-counselling-physician` | `snbc.counselling.physician@example.com` | 0900 000 0116 |
| Ramon Villanueva, RN | Donor/Collection | Donor Screening Physician<br>Phlebotomist / Registered Nurse (also)<br>Apheresis Specialist (also)<br>Donor Care / Medical Receptionist (also) | COL-001 | `snbc-screening-physician` | `snbc.screening.physician@example.com` | 0900 000 0102 |
| Liza Bautista | Donor/Collection | Phlebotomist / Registered Nurse | COL-002 | `snbc-phlebotomist` | `snbc.phlebotomist@example.com` | 0900 000 0103 |
| Marvin Castillo | Donor/Collection | Apheresis Specialist | COL-003 | `snbc-apheresis-specialist` | `snbc.apheresis.specialist@example.com` | 0900 000 0104 |
| Grace Domingo | Donor/Collection | Donor Care / Medical Receptionist | COL-004 | `snbc-medical-receptionist` | `snbc.medical.receptionist@example.com` | 0900 000 0105 |
| Kristine Lim | Testing | Serology / Molecular Medical Technologist | TST-002 | `snbc-serology-technologist` | `snbc.serology.technologist@example.com` | 0900 000 0109 |
| Dennis Ocampo | Testing | Laboratory Supervisor | TST-001 | `snbc-lab-supervisor` | `snbc.lab.supervisor@example.com` | 0900 000 0108 |
| Arnel Navarro | Processing | Component Laboratory Medical Technologist | PRO-001 | `snbc-component-technologist` | `snbc.component.technologist@example.com` | 0900 000 0106 |
| Joy Salazar | Processing | Processing Laboratory Assistant | PRO-002 | `snbc-processing-assistant` | `snbc.processing.assistant@example.com` | 0900 000 0107 |
| Rowena Tan | Issuance | Inventory Control Officer | ISS-001 | `snbc-inventory-control-officer` | `snbc.inventory.control.officer@example.com` | 0900 000 0110 |
| Jerome Flores | Issuance | Dispatch / Transport Coordinator | ISS-002 | `snbc-dispatch-coordinator` | `snbc.dispatch.coordinator@example.com` | 0900 000 0111 |
| Paolo Rivera | Issuance | IT Data Entry Clerk | ISS-003 | `snbc-it-data-clerk` | `snbc.it.data.clerk@example.com` | 0900 000 0112 |
| Andrea Mendoza | Billing | Billing Clerk | BIL-002 | `snbc-billing-clerk` | `snbc.billing.clerk@example.com` | 0900 000 0114 |
| Carmela Reyes | Billing | Billing Supervisor | BIL-001 | `snbc-billing-supervisor` | `snbc.billing.supervisor@example.com` | 0900 000 0113 |

¹ Kept from the Counselling department, which was withdrawn on 2026-10-26. With no department, role or level, the account opens no department pages.

## PRC Davao Blood Services

A blood centre: it collects, tests, processes and issues blood. Abbreviation **`PRC`**, so its bags are numbered `PRC-<barcode>-<component>`.

- **Address:** Davao City · open Mon - Sat 7 AM - 4 PM
- **Primary account:** Marilou Gutierrez (`prc-supervisor`)
- **Settings:** shelf life and price for every component (below), 13 deferral codes (R1, R2-Low, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R2-High), and 56 stock thresholds (one per component and blood type).
- **Demo stock:** 2,800 available units, 50 of every component in every blood type.

| Component | Code | Shelf life | Price |
|---|---|---|---|
| Whole Blood | `WB` | 35 days | ₱1,500.00 |
| Packed RBC | `PRBC` | 42 days | ₱2,000.00 |
| Fresh Frozen Plasma | `FFP` | 365 days | ₱1,000.00 |
| Platelet Concentrate | `PC` | 5 days | ₱1,250.00 |
| Cryoprecipitate | `CRYO` | 365 days | ₱1,000.00 |
| Washed RBC | `WRBC` | 28 days | ₱999.00 |
| Cryosupernate | `CSP` | 365 days | ₱1,500.00 |

### Staff (16)

| Name | Department | Role | Employee ID | Username | Email | Phone |
|---|---|---|---|---|---|---|
| Ernesto Magbanua | — | **Blood Center Admin** | ADM-001 | `prc-center-admin` | `prc.center.admin@example.com` | 0900 000 0215 |
| Marilou Gutierrez | — | **Supervisor** | SUP-001 | `prc-supervisor` | `prc.supervisor@example.com` | 0900 000 0201 |
| Teresita Valdez | — | None¹ | CNS-001 | `prc-counselling-physician` | `prc.counselling.physician@example.com` | 0900 000 0216 |
| Antonio Lacson, RN | Donor/Collection | Donor Screening Physician<br>Phlebotomist / Registered Nurse (also)<br>Apheresis Specialist (also)<br>Donor Care / Medical Receptionist (also) | COL-001 | `prc-screening-physician` | `prc.screening.physician@example.com` | 0900 000 0202 |
| Rosalie Manalo | Donor/Collection | Phlebotomist / Registered Nurse | COL-002 | `prc-phlebotomist` | `prc.phlebotomist@example.com` | 0900 000 0203 |
| Ferdinand Cabrera | Donor/Collection | Apheresis Specialist | COL-003 | `prc-apheresis-specialist` | `prc.apheresis.specialist@example.com` | 0900 000 0204 |
| Jasmine Soriano | Donor/Collection | Donor Care / Medical Receptionist | COL-004 | `prc-medical-receptionist` | `prc.medical.receptionist@example.com` | 0900 000 0205 |
| Charmaine Morales | Testing | Serology / Molecular Medical Technologist | TST-002 | `prc-serology-technologist` | `prc.serology.technologist@example.com` | 0900 000 0209 |
| Benjamin Espinosa | Testing | Laboratory Supervisor | TST-001 | `prc-lab-supervisor` | `prc.lab.supervisor@example.com` | 0900 000 0208 |
| Rodel Pascual | Processing | Component Laboratory Medical Technologist | PRO-001 | `prc-component-technologist` | `prc.component.technologist@example.com` | 0900 000 0206 |
| Lorna Velasco | Processing | Processing Laboratory Assistant | PRO-002 | `prc-processing-assistant` | `prc.processing.assistant@example.com` | 0900 000 0207 |
| Victor Panganiban | Issuance | Inventory Control Officer | ISS-001 | `prc-inventory-control-officer` | `prc.inventory.control.officer@example.com` | 0900 000 0210 |
| Ronald Abella | Issuance | Dispatch / Transport Coordinator | ISS-002 | `prc-dispatch-coordinator` | `prc.dispatch.coordinator@example.com` | 0900 000 0211 |
| Shiela Bacani | Issuance | IT Data Entry Clerk | ISS-003 | `prc-it-data-clerk` | `prc.it.data.clerk@example.com` | 0900 000 0212 |
| Princess Agustin | Billing | Billing Clerk | BIL-002 | `prc-billing-clerk` | `prc.billing.clerk@example.com` | 0900 000 0214 |
| Imelda Dizon | Billing | Billing Supervisor | BIL-001 | `prc-billing-supervisor` | `prc.billing.supervisor@example.com` | 0900 000 0213 |

¹ Kept from the Counselling department, which was withdrawn on 2026-10-26. With no department, role or level, the account opens no department pages.

## SPMC Blood Bank

A blood centre: it collects, tests, processes and issues blood. Abbreviation **`SPMC`**, so its bags are numbered `SPMC-<barcode>-<component>`.

- **Address:** Davao City · open 24/7
- **Primary account:** Lourdes Bernardo (`spmc-supervisor`)
- **Settings:** shelf life and price for every component (below), 13 deferral codes (R1, R2-Low, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R2-High), and 56 stock thresholds (one per component and blood type).
- **Demo stock:** 2,800 available units, 50 of every component in every blood type.

| Component | Code | Shelf life | Price |
|---|---|---|---|
| Whole Blood | `WB` | 35 days | ₱1,500.00 |
| Packed RBC | `PRBC` | 42 days | ₱2,000.00 |
| Fresh Frozen Plasma | `FFP` | 365 days | ₱1,000.00 |
| Platelet Concentrate | `PC` | 5 days | ₱1,250.00 |
| Cryoprecipitate | `CRYO` | 365 days | ₱1,000.00 |
| Washed RBC | `WRBC` | 28 days | ₱999.00 |
| Cryosupernate | `CSP` | 365 days | ₱1,500.00 |

### Staff (16)

| Name | Department | Role | Employee ID | Username | Email | Phone |
|---|---|---|---|---|---|---|
| Wilfredo Sison | — | **Blood Center Admin** | ADM-001 | `spmc-center-admin` | `spmc.center.admin@example.com` | 0900 000 0315 |
| Lourdes Bernardo | — | **Supervisor** | SUP-001 | `spmc-supervisor` | `spmc.supervisor@example.com` | 0900 000 0301 |
| Corazon Yap | — | None¹ | CNS-001 | `spmc-counselling-physician` | `spmc.counselling.physician@example.com` | 0900 000 0316 |
| Rogelio Cortez, RN | Donor/Collection | Donor Screening Physician<br>Phlebotomist / Registered Nurse (also)<br>Apheresis Specialist (also)<br>Donor Care / Medical Receptionist (also) | COL-001 | `spmc-screening-physician` | `spmc.screening.physician@example.com` | 0900 000 0302 |
| Aileen Dumalagan | Donor/Collection | Phlebotomist / Registered Nurse | COL-002 | `spmc-phlebotomist` | `spmc.phlebotomist@example.com` | 0900 000 0303 |
| Joel Sarmiento | Donor/Collection | Apheresis Specialist | COL-003 | `spmc-apheresis-specialist` | `spmc.apheresis.specialist@example.com` | 0900 000 0304 |
| Kimberly Tolentino | Donor/Collection | Donor Care / Medical Receptionist | COL-004 | `spmc-medical-receptionist` | `spmc.medical.receptionist@example.com` | 0900 000 0305 |
| Hazel Ybanez | Testing | Serology / Molecular Medical Technologist | TST-002 | `spmc-serology-technologist` | `spmc.serology.technologist@example.com` | 0900 000 0309 |
| Gilbert Cuenca | Testing | Laboratory Supervisor | TST-001 | `spmc-lab-supervisor` | `spmc.lab.supervisor@example.com` | 0900 000 0308 |
| Ariel Mercado | Processing | Component Laboratory Medical Technologist | PRO-001 | `spmc-component-technologist` | `spmc.component.technologist@example.com` | 0900 000 0306 |
| Nenita Baguio | Processing | Processing Laboratory Assistant | PRO-002 | `spmc-processing-assistant` | `spmc.processing.assistant@example.com` | 0900 000 0307 |
| Reynaldo Quimpo | Issuance | Inventory Control Officer | ISS-001 | `spmc-inventory-control-officer` | `spmc.inventory.control.officer@example.com` | 0900 000 0310 |
| Mark Anthony Sumalinog | Issuance | Dispatch / Transport Coordinator | ISS-002 | `spmc-dispatch-coordinator` | `spmc.dispatch.coordinator@example.com` | 0900 000 0311 |
| Jenny Lagahit | Issuance | IT Data Entry Clerk | ISS-003 | `spmc-it-data-clerk` | `spmc.it.data.clerk@example.com` | 0900 000 0312 |
| Rhea Alcantara | Billing | Billing Clerk | BIL-002 | `spmc-billing-clerk` | `spmc.billing.clerk@example.com` | 0900 000 0314 |
| Evelyn Pacana | Billing | Billing Supervisor | BIL-001 | `spmc-billing-supervisor` | `spmc.billing.supervisor@example.com` | 0900 000 0313 |

¹ Kept from the Counselling department, which was withdrawn on 2026-10-26. With no department, role or level, the account opens no department pages.

## SPMC Hospital Blood Bank

A hospital blood bank: it requests blood from the centres, receives it, keeps it on its own shelf, and tags, crossmatches and transfuses it for its patients. It has roles, not departments.

- **Address:** J.P. Laurel Ave, Davao City, Philippines
- **Primary account:** User Name (`spmch-head`), the Head
- **Request days:** weekly requests to Sub-National Blood Center on Thursday, Friday, Saturday.
- **Demo stock:** 44 available bags, received earlier from SNBC, PRC and SPMC: Whole Blood O+ 4, A+ 3; Packed RBC O+ 8, A+ 6, B+ 4, AB+ 2; Fresh Frozen Plasma O+ 5, A+ 4, B+ 3; Cryoprecipitate O+ 3, AB+ 2. No platelets, washed RBC or cryosupernate, and most blood types missing, so the shelf has gaps to request from a centre.

### Staff (3)

| Name | Role | Username | Email | Phone |
|---|---|---|---|---|
| User Name | **Blood Bank Head** | `spmch-head` | `spmch.head@example.com` | 0900 000 0401 |
| Gemma Abarquez | **Inventory Staff** | `spmch-inventory` | `spmch.inventory@example.com` | 0900 000 0402 |
| Dexter Lim | **Requesting Staff** | `spmch-requesting` | `spmch.requesting@example.com` | 0900 000 0403 |

## Davao Doctors Hospital Blood Bank

A hospital blood bank: it requests blood from the centres, receives it, keeps it on its own shelf, and tags, crossmatches and transfuses it for its patients. It has roles, not departments.

- **Address:** E. Quirino Ave, Davao City
- **Primary account:** Marissa Uy (`ddh-head`), the Head
- **Request days:** weekly requests to Sub-National Blood Center on Thursday, Friday, Saturday.
- **Demo stock:** 34 available bags, received earlier from SNBC, PRC and SPMC: Whole Blood O+ 3, B+ 2; Packed RBC O+ 6, B+ 5, O- 2; Fresh Frozen Plasma A+ 5, O+ 4, AB+ 2; Cryoprecipitate A+ 3, B+ 2. No platelets, washed RBC or cryosupernate, and most blood types missing, so the shelf has gaps to request from a centre.

### Staff (3)

| Name | Role | Username | Email | Phone |
|---|---|---|---|---|
| Marissa Uy | **Blood Bank Head** | `ddh-head` | `ddh.head@example.com` | 0900 000 0501 |
| Jonathan Belarmino | **Inventory Staff** | `ddh-inventory` | `ddh.inventory@example.com` | 0900 000 0502 |
| Cheryl Go | **Requesting Staff** | `ddh-requesting` | `ddh.requesting@example.com` | 0900 000 0503 |

## Super Admins

RedAgos's own administrators. They belong to no facility: they create facilities, set a blood centre's abbreviation, review donor IDs and manage the other administrators. They were kept as they were in the reset and keep their own passwords, not the demo one.

| Name | Username | Email |
|---|---|---|
| Admin Minad | `Admin` | `admin@redagos.ph` |
| Admin Admin | `ushd` | `new@redagos.test` |

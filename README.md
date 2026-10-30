# School ERP

The School ERP includes academic management, parent/student portals, fee management, examination workflows, HRMS/payroll, and operational administration:

- Laravel 12 REST API with PostgreSQL configuration
- Next.js and TypeScript web application
- Cookie-based Laravel Sanctum SPA authentication and server-enforced RBAC
- School profile, academic sessions, classes, sections and subjects
- Audit history for foundation and access-control changes
- Responsive dashboard and setup screens backed by API data
- Student admissions, guardian linking, enrollment history, class transfers, promotion, withdrawal and CSV import/export
- Employee records, teaching assignments and CSV import/export
- Restricted student medical-record access and private student/employee document storage
- Student, employee and teacher-assignment workspace screens
- Subject management, examination schedules, marks entry and printable report cards
- Homework assignments, weekly timetable conflict checking, and audience-targeted notices
- Parent/student self-registration using school-issued, one-time activation codes
- Student portal profile, attendance history, examination results, printable report cards, fee balance/payment history, and announcements
- Staff attendance marking, fee charges and manual payment recording
- Shared-database, school-scoped records and super-admin school switching
- Razorpay online fee orders with signed webhook capture
- Email notifications through Laravel's configured mailer and SMS/WhatsApp through Twilio
- Library circulation, transport assignments, payroll records, and inventory stock ledger
- Expense approvals, event budgets, school asset lifecycle, and transport fleet controls

This is a development foundation, not a production deployment. See the [deployment and recovery runbook](./docs/DEPLOYMENT-RUNBOOK.md), [administrator guide](./docs/ADMINISTRATOR-GUIDE.md), and [UAT checklist](./docs/UAT-CHECKLIST.md). Production hosting, secret management, provider setup, restore evidence, and school sign-off are still required before using real student data or money.

## Architecture

`web/` is the Next.js frontend and `api/` is the only backend. The frontend calls the Laravel REST API; PostgreSQL is the target relational database. Authentication uses Sanctum's stateful, HttpOnly session cookie flow rather than storing bearer tokens in browser storage. API middleware enforces permissions independently of UI visibility.

Core relationships:

- Users ↔ Roles ↔ Permissions through normalized many-to-many tables.
- Academic sessions own classes and subjects.
- Subjects are explicitly mapped to one or more classes within the same academic session; exams, homework, timetables, and teacher assignments only accept subjects mapped to their selected class.
- Classes own sections.
- Students have guardian links and enrollment records; promotions, transfers and withdrawals retain history.
- Employees may be assigned to session-specific classes, sections and subjects.
- Exam schedules contain dated subject papers; marks are stored per active student enrollment. Report cards are printable browser views.
- Timetable slots are assigned per section and reject overlapping room/teacher times.
- Notices can target all users, staff, students, or parents/guardians.
- Parent accounts link to all active students associated with the guardian record; student accounts link to one student record. Staff issue activation codes, which expire after 30 days and are redeemed once.
- Attendance is recorded per active enrollment and date. Teachers can record attendance only for assigned sections.
- Fee charges and manual payment entries are append-only ledger records; a payment cannot exceed the current outstanding balance. Portal users can view but cannot edit fee data.
- School-owned records are scoped to the active school. Super-admins can switch schools; staff roles receive module-specific access.
- Online fee payments are created through Razorpay; only a verified webhook records a captured payment. A captured amount that exceeds the remaining balance is flagged for reconciliation rather than silently over-applied.
- Notification attempts are recorded with provider delivery status. WhatsApp messages must comply with Twilio/WhatsApp template and conversation-window requirements.
- Library loans, transport assignments, payroll records, and inventory transactions retain school ownership; stock issue operations reject quantities above available stock.
- Audit entries optionally reference the actor and the affected model.

## Requirements

- Node.js 20.9 or newer and npm
- PHP 8.2+ with `pdo_pgsql`, `openssl`, and `mbstring`
- Composer 2
- PostgreSQL 14+

## Local setup

1. Create a PostgreSQL database named `school_erp`.
2. Copy `api/.env.example` to `api/.env`; set the database credentials and generate the app key with `php artisan key:generate`.
3. Configure the initial administrator in `api/.env` before seeding. Use an email and a unique password of at least 12 characters:

   ```dotenv
   INITIAL_ADMIN_NAME="School Administrator"
   INITIAL_ADMIN_EMAIL=admin@example.test
   INITIAL_ADMIN_PASSWORD=replace-with-a-long-unique-password
   ```

   The seeder does not create a login account unless both credentials are supplied.
4. Install and initialize the API:

   ```powershell
   cd api
   composer install
   php artisan migrate --seed
   php artisan serve --host=localhost --port=8000
   ```

5. In another terminal, copy `web/.env.example` to `web/.env.local`, install and run the frontend:

   ```powershell
   cd web
   npm install
   npm run dev
   ```

6. Open `http://localhost:3000`. The API allows credentialed requests only from `FRONTEND_URL`. For local cookie authentication, use `localhost` consistently rather than mixing it with `127.0.0.1`.

For local development convenience, `web/.env.local` may define `DEV_LOGIN_EMAIL` and `DEV_LOGIN_PASSWORD`. The login page retrieves and autofills those values only from a loopback development server; the password stays server-side until the local browser requests it and is not included in production frontend bundles. This development-only autofill must not be used for production credentials. The password field remains masked. The loopback development login page also shows buttons for the four seeded demo accounts; those buttons use the standard password login and are not available in production.

For production, set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and use HTTPS for both apps. Set `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` to the actual trusted frontend origin/host; do not use the local defaults. Set `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, and `RAZORPAY_WEBHOOK_SECRET` from the Razorpay dashboard and configure the webhook URL as `/api/webhooks/razorpay`. Configure `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_SMS`, and `TWILIO_FROM_WHATSAPP` to enable those channels. Email uses Laravel's configured `MAIL_*` transport. Keep all provider credentials server-side and out of source control.

The local PHP distribution must have PostgreSQL's PDO driver enabled. If it is unavailable, install/enable `pdo_pgsql`; do not change the production database configuration to SQLite.

## Validation

Run API tests with `cd api; php artisan test`. Run frontend checks with `cd web; npm run lint` and `npm run build`.
CI additionally runs PHP formatting, Composer/npm production dependency audits, PostgreSQL migrations, API tests, frontend lint, TypeScript, and a production build on pushes and pull requests.

### Demo data for testing

After migrations and permission seeding, run `cd api; php artisan db:seed --class=DemoDataSeeder` to add an isolated, repeatable **Demo School** dataset. It includes admin, teacher, parent, student, accountant, operations, and HR accounts, plus academic records, attendance, published results, fees/receipts, HR/payroll, transport, library, inventory, events/expenses, assets, notices, and a synthetic notification log. No external email/SMS or payment gateway calls are made. The seeder refuses to run in production.

Demo account password: `DemoSchool2026!`

| Role | Email |
|---|---|
| School admin | `admin.demo@example.test` |
| Teacher | `teacher.demo@example.test` |
| Parent | `parent.demo@example.test` |
| Student | `student.demo@example.test` |
| Non-teaching staff | `staff.demo@example.test` |
| Accountant | `accountant.demo@example.test` |
| Operations manager | `operations.demo@example.test` |
| HR manager | `hr.demo@example.test` |

An unlinked student's test activation code is `DEMO-STUDENT-ACTIVATE-2026`. All accounts and records are synthetic, use a dedicated demo school, and must never be copied into production. To seed during a normal `php artisan db:seed`, explicitly set `SEED_DEMO_DATA=true`; it defaults to false.

## Phase 1 status

- [x] Application scaffolds and selected architecture
- [x] Session-based authentication with login throttling
- [x] Role/permission schema and server-side permission middleware
- [x] Initial academic and school settings schema/API
- [x] Audit log schema and recording
- [x] Responsive dashboard and academic setup shell
- [x] Database-backed API workflow tests (SQLite and PostgreSQL)
- [ ] Production secrets, HTTPS, backup/restore, and deployment configuration

## Phase 2 status

- [x] Student admission, guardian linking, enrollment, transfer, promotion and withdrawal workflows
- [x] Employee records and teaching assignments
- [x] CSV templates, import validation and export endpoints
- [x] Restricted medical records and private document APIs
- [x] Student, employee and teacher-assignment screens
- [x] Role-scoped student visibility and permission enforcement
- [x] Phase 2 API workflow tests
- [x] Subject management, exam schedules, marks entry, and printable report cards
- [x] Homework, timetable conflict validation, and notices/announcements

Report card percentages use the recorded marks across all scheduled subjects. The initial display grade scale is A+ (90+), A (80+), B (70+), C (60+), D (50+), and F (below 50); report cards show `PENDING` until every subject mark is entered. This initial grade scale is fixed in the application and can be made configurable in a later iteration.

## Phase 3 status

- [x] Parent and student login roles with one-time school-issued activation codes
- [x] Student profile, attendance history, examination results, printable/downloadable report-card view, fees and payment history, and announcements
- [x] Staff attendance marking and fee charge/manual payment screens
- [x] Parent/student data ownership checks and teacher attendance assignment scope
- [x] Phase 3 portal API workflow tests

Report cards use the browser print dialog, where users can save the printable view as a PDF. Fee charges and manual payments are supported alongside configured Razorpay checkout; online payments require valid provider credentials and the webhook configured in Phase 4 setup.

## Phase 4 status

- [x] School-scoped shared-database foundation and school switching
- [x] Razorpay order creation and signature-verified webhook capture
- [x] Twilio SMS/WhatsApp and configured-mailer email delivery with provider-submission status logs
- [x] Library circulation, transport setup/assignment, payroll records, and inventory stock tracking
- [x] Class/session fee structures, student invoices, concessions, and one-time fixed late fees after the due date
- [x] Invoice-linked payments, printable receipts, and fee collection/outstanding reports
- [ ] Production provider credentials, webhook registration, provider-side delivery verification, and financial reconciliation runbook

Fee structures apply to an academic session and class. Issuing a structure creates one invoice per active enrolment; invoice concessions are fixed amounts, and the structure's fixed late fee is included once in the amount due when an unpaid invoice is past due. Existing student-level charges and payments remain supported.

## Phase 5 status

- [x] Examination schedules with per-subject dates, times, duration, and marks thresholds
- [x] Marks entry with bounds and teaching-assignment validation
- [x] Configurable percentage grading and result calculations
- [x] Complete-marks validation, result approval, and locking before publication
- [x] Report-card generation and explicit publication to student/parent portals

Results remain staff-only until all active students have marks for every scheduled subject, an authorized user approves them, and an authorized user publishes them. Published results are read-only; correcting a result requires a future explicit reopening workflow.

## Phase 6 status

- [x] Employee leave requests with overlap checks, approval/rejection decisions, and audit history
- [x] Effective-dated salary structures with a base salary and named fixed allowance/deduction components
- [x] Monthly payroll calculation from the salary structure effective on the first day of the pay period
- [x] Draft, approval, and payment stages with creator/approver/payer separation and audit entries
- [x] Approved salary slips printable from the payroll register and period-based payroll reports

Approved leave is tracked separately and is not automatically deducted from salary. Salary structures are versioned; monthly payroll snapshots its selected structure and component totals so later structure changes do not rewrite existing payroll. A different authorized user must approve and pay a payroll record than the user who created it.

## Phase 8 status

- [x] Cross-module API workflow and permission tests run in CI
- [x] Data-import templates and CSV import/export workflows for student and employee records documented
- [x] PostgreSQL backup, checksum-verified restore, and isolated recovery-test utilities prepared
- [x] Administrator operations guide and school UAT checklist documented
- [ ] Production security configuration, dependency audit remediation, and environment-specific deployment sign-off
- [ ] Backup/recovery test executed against the deployment PostgreSQL version and recovery objectives approved
- [ ] School user acceptance tests completed and training acknowledged
- [ ] Production deployment, monitoring, provider reconciliation, and operational handover

Phase 8 is **prepared but not complete**: CI and local automated tests do not constitute a production deployment, a successful production backup restore, or school acceptance. The production environment and authorized UAT participants have not been supplied.

## Phase 7 status

- [x] School-scoped vehicle and route management with maintenance/inactive status and active-rider capacity protections
- [x] Student route allocation with duplicate-allocation checks, vehicle-capacity enforcement, and assignment history
- [x] Operational expense submission, independent approval/rejection, payment recording, and status-based reports
- [x] School asset register with value, location, custodian, warranty, condition, and immutable lifecycle history
- [x] Event planning, budgets, linked expense review, committed-spend reporting, and over-budget flags

Event-linked expenses follow the same independent review and payment controls as general expenses. Pending expenses are reported separately from committed spend; approved and paid expenses count toward event budget totals. A budget overrun is visible for review but does not prevent approval. Disposed assets and completed/cancelled events are terminal records.

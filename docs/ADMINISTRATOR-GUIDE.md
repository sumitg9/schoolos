# School ERP administrator guide

## Access and first setup

1. Sign in with an administrator account and confirm the active school in the workspace header.
2. Review **Access control** before inviting staff. Assign named roles, not broad permissions by convenience. The API checks permissions even when a screen is hidden.
3. Configure the school profile and academic session, then create classes, sections, and subjects.
4. Create employee and student records. Use the import templates for supported CSV imports; verify row errors and counts before relying on imported records.
5. Issue parent/student portal activation codes only to the intended guardian/student, and deliver codes through an approved private channel.
6. Confirm users can access only their assigned school and approved modules. Never share administrator credentials.

## Daily operations

- **Students and employees:** Maintain profiles and enrollment/employment status. Use transfer/promotion workflows to preserve student history; do not create duplicate profiles to represent a move.
- **Attendance and exams:** Mark attendance for the correct date and class. Enter marks against the scheduled subject, check the report card, approve complete results, then publish only after review.
- **Fees:** Assign fee structures to the correct session/class, issue invoices, record payments against the correct student/invoice, and review outstanding/collection reports. Only verified provider webhooks settle online payments.
- **Leave and payroll:** Record leave for the correct employee and dates. Create effective-dated salary structures before monthly payroll. Review the draft, have a different authorized user approve it, and record payment with a reference. Leave is not automatically deducted.
- **Transport:** Check active route and vehicle capacity before assignments. End student assignments before taking a route or vehicle out of service.
- **Expenses and events:** Link event costs to the event, submit receipts/references, and require an independent reviewer. Reconcile pending/approved/paid totals; investigate visible budget overruns.
- **Assets and inventory:** Give each asset a unique tag. Record custodian, location, condition, and lifecycle changes. Disposed assets are terminal. Record inventory movements rather than changing stock outside the ledger.
- **Audit trail:** Use audit logs for approval, publishing, payment, import, and lifecycle investigations. Restrict access to finance and personal data.

## Role separation and sensitive records

- Grant the minimum module permissions needed for a user's job.
- Payroll/expense creators must not approve or pay their own records.
- Keep medical records and private staff/student documents restricted to authorized roles.
- Parent/student portal accounts are limited to linked student records and published results.
- Remove access promptly when a staff member changes role or leaves.
- Review role assignments and active accounts periodically; investigate unexpected school switches and audit events.

## Import, export, and support

- Download the current CSV template from the relevant workspace page before preparing a file. Preserve headers and date/boolean formats.
- Test with a small file, resolve row-level errors, and reconcile successful row counts before a large import.
- Export only for an approved business purpose. Store the file securely and delete temporary copies under school retention policy.
- Report unexpected totals, duplicate records, missing permissions, or suspected data exposure to the designated system administrator. Include the timestamp, affected school/module, and record reference; never send passwords or provider secrets.

## Training and UAT

Before go-live, have a representative administrator, teacher, accountant, HR manager, operations manager, and parent/student tester work through the [UAT checklist](./UAT-CHECKLIST.md) in a non-production environment. Capture the tester, result, date, and defects for each scenario. Production sign-off is a school decision and is not implied by automated tests.

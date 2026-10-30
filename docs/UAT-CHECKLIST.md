# User acceptance testing checklist

Run in a non-production environment with representative test accounts and synthetic student, employee, payment, and health data. Record **Tester / Date / Pass or Fail / Evidence or issue ID** for each item. Do not use real student or payment data in test environments.

## Identity, permissions, and tenancy

- [ ] Sign in, sign out, CSRF-protected write, session expiry, and invalid-login behavior work as expected.
- [ ] Each role sees only permitted navigation and API operations; direct API calls without permission are denied.
- [ ] Teacher scope is limited to assigned class/subject; parent/student scope is limited to linked records.
- [ ] Switching schools as a super-admin changes the visible dataset; a normal staff account cannot switch or access another school's records.
- [ ] Restricted medical records and uploaded documents are not accessible to unauthorized users or public URLs.
- [ ] Audit entries identify the actor and meaningful operation for sensitive create/review/payment/publish actions.

## Academic and family portal

- [ ] Enroll a student, link a guardian, transfer/promote the student, and verify enrollment history.
- [ ] Record attendance and verify the parent/student portal shows only its linked student's attendance.
- [ ] Schedule subjects without time conflicts, enter marks, reject incomplete approval, approve complete marks, and publish results.
- [ ] Verify unpublished results are hidden from family/student accounts and published report cards render and print correctly.
- [ ] Create a notice with the intended audience and verify only that audience can see it.

## Fees and communications

- [ ] Issue class/session invoices with concessions and verify outstanding/late fee totals.
- [ ] Record a manual payment and verify balance, receipt, and collection report.
- [ ] In payment-provider test mode, create an order, reject an invalid signature, accept a valid capture once, and reconcile duplicate/overpayment events.
- [ ] Exercise email/SMS/WhatsApp only with provider-approved test recipients and verify delivery status/error visibility.

## HRMS and payroll

- [ ] Submit and review leave, reject overlapping requests, and verify audit history.
- [ ] Create effective-dated salary structures; calculate a pay period using the first-day structure.
- [ ] Confirm payroll creator cannot approve/pay their own record; a second authorized user approves and a distinct authorized payer records settlement.
- [ ] Print an approved salary slip and reconcile period report totals against its components.

## Transport, expenses, assets, and events

- [ ] Assign students to routes and verify duplicate assignment and vehicle capacity protections.
- [ ] Attempt to take a vehicle/route out of service with active riders and verify it is blocked until assignments end.
- [ ] Submit an expense, prevent self-approval, approve/reject with another user, and record payment/reference.
- [ ] Create an event, link pending/approved/paid expenses, and verify only approved/paid amounts count as committed spend and overruns are flagged.
- [ ] Register an asset, change its custodian/location/condition/status, inspect lifecycle history, and verify disposal is terminal.

## Data operations and recovery

- [ ] Import a small student and employee CSV using downloaded templates; verify valid rows, rejected rows, school scope, and resulting counts.
- [ ] Export data using an authorized account and verify the exported data is handled under retention rules.
- [ ] Inspect and apply a representative schema migration in staging after taking a backup.
- [ ] Run the isolated PostgreSQL backup/restore test and verify migration metadata and representative records.
- [ ] Verify monitoring, alert routing, queue restart, backup retention, and recovery contacts with the operations owner.

## Sign-off

- [ ] All critical scenarios pass or have an approved, documented mitigation.
- [ ] Defects have owners and target dates; no unresolved critical data-loss, authorization, privacy, or payment-reconciliation issue remains.
- [ ] School administrator, academic representative, finance representative, HR representative, and operations owner sign off on the release.

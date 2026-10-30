# Production deployment and recovery runbook

## Deployment status

This repository contains application code and a CI workflow, but it does not identify a production hosting provider, domain, secret manager, database service, or approved maintenance window. No production deployment has been performed. Complete the environment-specific decisions and sign-offs below before onboarding real students or processing real money.

## Required production configuration

- Provision supported PHP 8.2+, PostgreSQL 14+, and Node.js 20.9+ (Node.js 22 is used in CI).
- Serve both the web application and API over HTTPS. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, and `SESSION_SECURE_COOKIE=true`.
- Set `SANCTUM_STATEFUL_DOMAINS` to the exact trusted frontend/API hosts and `SESSION_DOMAIN` only when the deployment's subdomain layout requires it. Do not retain local development hosts.
- Use a stable, private `APP_KEY`; never regenerate it during routine deployments.
- Store database, mail, payment, and SMS credentials in the selected hosting secret manager. Do not put secret values in source control, build arguments, frontend variables, deployment logs, or support tickets.
- Configure production mail and queue workers, cache/session stores, private document storage, log retention, monitoring, and alerting. Verify uploaded student/employee documents are not publicly readable.
- Configure Razorpay webhook secrets and the production webhook URL only after payment-provider ownership, test-mode reconciliation, and finance sign-off.
- Configure Twilio and email sender identity only after provider approval and delivery tests.
- Create the initial administrator through the documented secured process, enforce a unique password and MFA at the identity/provider layer where available, and remove temporary bootstrap credentials after first sign-in.

## Release procedure

1. Require a reviewed change and successful CI on the exact release commit.
2. Confirm database backup freshness, backup checksum, restore-test evidence, queue health, monitoring, and the agreed rollback owner.
3. Schedule a maintenance window when the migration has locking or compatibility risk. Put the application into maintenance mode if the release is not backward-compatible.
4. Deploy the immutable API and frontend release artifacts. Do not run Composer or npm dependency resolution on a live web request path.
5. Run `php artisan migrate --force` once from the release job, then `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` from the API release directory.
6. Restart queue workers and application processes using the hosting platform's controlled restart mechanism.
7. Check `GET /up`, the API root `/`, frontend login, session/CSRF flow, school scoping, permission boundaries, document privacy, and an end-to-end smoke workflow with non-production test data.
8. Review application, queue, database, and provider logs. Reconcile test/production payment-provider events before enabling online collection.
9. End maintenance only after the smoke checks pass. Record release SHA, migration status, approver, backup reference, and verification evidence.
10. Roll back application artifacts only when the current schema remains compatible. Database rollback is a separate recovery decision; do not run `migrate:rollback` automatically against production data.

## PostgreSQL backup

The PowerShell helper uses PostgreSQL client tools and the connection environment (`PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD` or an approved `.pgpass`/secret-manager integration). It writes a custom-format dump and a SHA-256 manifest. The backup contains sensitive personal and financial data: restrict file ACLs, encrypt it at rest and in transit, keep an access-controlled off-site copy, and apply an approved retention schedule.

```powershell
$env:PGHOST = "your-database-host"
$env:PGPORT = "5432"
$env:PGUSER = "your-backup-role"
# Inject credentials through the deployment secret manager; do not type or log them here.
.\scripts\Backup-Postgres.ps1 -Database "school_erp" -Destination "D:\private-backups"
```

Keep encryption keys separate from database backups. A successful dump and checksum are not proof of recoverability; perform regular isolated restores.

## Isolated backup and recovery test

The recovery-test helper creates two uniquely named disposable databases, migrates/seeds the source, dumps it, restores into the target, and verifies migration metadata. It drops only the exact generated database names in its `finally` block. Review the script and confirm that the PostgreSQL account can create/drop those disposable databases before opting in:

```powershell
.\scripts\Test-PostgresRecovery.ps1 -RunRecoveryTest
```

The script uses configured PostgreSQL client environment variables and the API's `.env` connection settings. Do not run it with a restricted production account or during a production restore. Record timestamp, result, PostgreSQL version, and operator in the recovery log.

## Controlled restore

Restore only during an approved incident/change window, to the explicitly confirmed target, after preserving the current database where possible. Validate the dump's checksum before running the restore helper. `pg_restore --clean` removes existing objects in the specified target database:

```powershell
createdb --maintenance-db postgres "school_erp_recovery"
$env:ERP_ALLOW_DESTRUCTIVE_RESTORE = "YES"
.\scripts\Restore-Postgres.ps1 `
  -BackupFile "D:\private-backups\school_erp-20261030-020000.dump" `
  -TargetDatabase "school_erp_recovery" `
  -ConfirmTargetDatabase "school_erp_recovery"
Remove-Item Env:ERP_ALLOW_DESTRUCTIVE_RESTORE
```

The exact database name must be supplied twice, the opt-in environment flag must be set, and the adjacent SHA-256 manifest must match. Prefer restoring to a new isolated database first; validate migrations, record counts, school boundaries, recent audit entries, and representative fee/payroll/examination records before redirecting application traffic. Coordinate point-in-time recovery and transaction-log recovery with the database provider.

## Import and schema migration

- Student and employee CSV imports are available from the workspace screens. Download the matching import template from each screen; validate a small sample first, review row-level validation errors, then reconcile created counts and school scope before importing the full file.
- Student and employee exports require their separate export permissions. Treat exports as restricted personal data and store/delete them according to school retention policy.
- Take a verified backup before applying schema changes to production.
- Inspect pending migrations with `php artisan migrate:status`; inspect SQL with `php artisan migrate --pretend`; deploy using `php artisan migrate --force`.
- Migrations are additive where feasible. Review migration code and provider compatibility rather than assuming every change can be rolled back without data loss.
- The database is the source of truth. Do not import data by editing tables directly or reusing identifiers across schools.

## Operational sign-off checklist

- [ ] Hosting provider, regions/data residency, domain, and data-processing agreements approved
- [ ] Security owner, incident contact, and support escalation rota assigned
- [ ] Secrets and backups managed outside application files; restoration access tested
- [ ] HTTPS, cookie/CORS/Sanctum hosts, private document access, and RBAC verified in staging
- [ ] Payment, email, and messaging provider test events reconciled
- [ ] Monitoring, alerts, retention, queue restart, and disaster-recovery objectives agreed
- [ ] Administrator UAT and training signed off
- [ ] Release/rollback plan and production change window approved

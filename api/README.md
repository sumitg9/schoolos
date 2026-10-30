# School ERP API

This directory contains the Laravel 12 REST API. The Next.js application is in `../web`; do not add a second frontend or backend here.

For PostgreSQL setup, initial administrator configuration, and the complete local workflow, see the [project README](../README.md).

Run the API locally with:

```powershell
composer install
php artisan migrate --seed
php artisan serve --host=localhost --port=8000
```

Run automated API tests with `php artisan test`.

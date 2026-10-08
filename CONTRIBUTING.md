# Contributing

This package is maintained as a reusable Laravel library. Keep changes narrowly scoped and preserve the core boundary: the Laravel package must not depend on Filament or another audit engine.

Before opening a change:

1. Run `composer validate --no-check-publish`.
2. Run `composer lint`, `composer analyse`, and `composer test`.
3. Add regression coverage for security, privacy, transaction, or compatibility behavior.
4. Do not use production data or change the reference application.

Database matrix verification is available through the manual GitHub Actions workflow. SQLite-only results must not be described as MySQL or PostgreSQL compatibility evidence.

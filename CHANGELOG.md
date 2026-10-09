# Changelog

## Unreleased

- Fixed published migration handling so consumer installs do not run duplicate audit-table migrations.
- Added publishable configuration and migration resources for consumer installation.
- Added Larastan/PHPStan level-5 analysis and an opt-in database compatibility workflow.
- Hardened model-event registration typing and documented Eloquent model properties.
- Added reproducible SQLite performance benchmarks for writes, serialization, redaction, and paginated queries.

## 0.1.0

- Initial public development line with synchronous recording, opt-in lifecycle auditing, privacy filtering, execution context, queue context propagation, optional authentication auditing, and impersonation attribution.

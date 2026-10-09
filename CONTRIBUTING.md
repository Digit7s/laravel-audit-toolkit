# Contributing

This package is maintained as a reusable Laravel library. Keep changes narrowly scoped and preserve the core boundary: the Laravel package must not depend on Filament or another audit engine.

## Prerequisites

- PHP `^8.5` and Composer.
- SQLite for the local Testbench suite.
- Docker, when running the MySQL 8.4 and PostgreSQL 16 database matrix locally or through CI.
- Git and a separate, disposable application for integration checks.

## Clone and install

```bash
git clone https://github.com/Digit7s/laravel-audit-toolkit.git
cd laravel-audit-toolkit
composer install
```

The published package manifest resolves dependencies from Packagist. Do not add a path repository to this package's committed `composer.json`.

## Sibling package development

Path repositories belong in the consuming application's `composer.json`, and their paths are resolved relative to that file. The following is only an example layout:

```text
workspace/
├── laravel-audit-toolkit/
├── filament-audit-toolkit/
└── playground/
    └── composer.json
```

For that layout, the playground could use temporary sibling overrides like these:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-audit-toolkit",
            "options": {
                "symlink": true,
                "versions": {
                    "digit7s/laravel-audit-toolkit": "0.1.0"
                }
            }
        },
        {
            "type": "path",
            "url": "../filament-audit-toolkit",
            "options": {
                "symlink": true,
                "versions": {
                    "digit7s/filament-audit-toolkit": "0.1.0"
                }
            }
        }
    ]
}
```

Adjust the relative paths to match your own checkout. After changing a sibling package, run `composer update digit7s/laravel-audit-toolkit digit7s/filament-audit-toolkit` in the consuming application as appropriate. Remove the temporary path entries and update again to return to normal Packagist dependencies.

The Filament package's CI workflow uses the same technique for its isolated package tests. A local playground can use it to test both providers, migrations, panel registration, authorization, and the existing application plugins without modifying a production application.

## Checks and tests

Run the focused checks before opening a pull request:

```bash
composer validate --no-check-publish
composer check-platform-reqs
composer lint
composer analyse
composer test
```

Use `composer benchmark` only for performance investigations. Database matrix verification is available through the manual GitHub Actions workflow. SQLite-only results must not be described as MySQL or PostgreSQL compatibility evidence.

Add regression coverage for security, privacy, transaction, or compatibility behavior. Do not use production data or change the reference application.

## Pull requests and security

Keep pull requests focused, explain behavioral or documentation changes, and include the relevant test output. Report suspected vulnerabilities privately as described in [SECURITY.md](SECURITY.md); do not open a public issue with credentials, production audit data, or personal data.

Release tags and Packagist publication are maintainer-controlled activities. Do not retag an existing release, force-push, or publish a release from a contributor checkout.

# Maintainer release checklist

- [ ] Confirm package and dependency constraints against the target Laravel line.
- [ ] Run Composer validation, platform checks, Pint, Larastan/PHPStan, and the focused suite.
- [ ] Verify a fresh GitHub VCS consumer can publish configuration and migrations, migrate, record an event, and query it.
- [ ] Execute the SQLite, MySQL, and PostgreSQL workflow against isolated databases.
- [ ] Verify the existing Filament playground in light and dark themes and at a mobile-width viewport.
- [ ] Review the public repository description, topics, license, security policy, and maintainer contact.
- [ ] Review privacy allowlists, authentication-event configuration, and host authorization.
- [ ] Run the optional benchmark and record runtime/database context.
- [ ] Verify the playground path repositories, symlinks, panel plugin, and View Website plugin.
- [ ] Review CHANGELOG, SECURITY, CONTRIBUTING, and README compatibility statements.
- [ ] Obtain company pilot approval before enabling authentication auditing in a consuming application.
- [ ] Do not publish a release or directory listing from this checklist.

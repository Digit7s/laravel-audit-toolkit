# Controlled pilot release checklist

- [ ] Confirm package and dependency constraints against the target Laravel line.
- [ ] Run Composer validation, platform checks, Pint, Larastan/PHPStan, and the focused suite.
- [ ] Execute the SQLite, MySQL, and PostgreSQL workflow against isolated databases.
- [ ] Review privacy allowlists, authentication-event configuration, and host authorization.
- [ ] Run the optional benchmark and record runtime/database context.
- [ ] Verify the playground path repositories, symlinks, panel plugin, and View Website plugin.
- [ ] Review CHANGELOG, SECURITY, CONTRIBUTING, and README compatibility statements.
- [ ] Obtain company pilot approval before enabling authentication auditing in a consuming application.
- [ ] Do not publish a release or directory listing from this checklist.

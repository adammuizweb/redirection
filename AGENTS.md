# Redirection Plugin

Redirection is a standalone plugin for Jyavani CMS. This repository is the authoritative development source.

## Boundaries

- Keep all product changes inside this repository.
- Do not modify Jyavani Core to bypass the current unresolved redirect contract.
- Keep `plugin.json` at the package root and keep release ZIPs flat.
- Use the `jyr_` prefix for functions, constants, settings, and database tables; use `jyr.` for audit events.
- Never hardcode a dashboard, login, registration, or administrator path.
- Keep static destinations under `static/plugins/redirection/`.
- Do not add external redirect support without an explicit permission and query-leakage threat review.
- Do not add regex, wildcard, or query-based matching without bounded execution and dedicated behavioral tests.

## Verification

- Run PHP syntax checks for every PHP file.
- Run `php tests/contract.php`, `php tests/security_contract.php`, and `php tests/package_contract.php`.
- Build the flat ZIP with `php tools/build-package.php <output>` and reopen it for inspection.
- Test install, redirect behavior, query preservation, hit counters, 404 aggregation, and deactivation on a disposable Jyavani site.

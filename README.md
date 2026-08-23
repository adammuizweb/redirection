# Redirection

Redirection is a focused Jyavani plugin for managing exact-path redirects and reviewing bounded 404 insights. It integrates at Jyavani's unresolved-route boundary, so valid Core, content, authentication, static, and plugin routes retain priority.

## Features

- Exact, case-sensitive source path matching.
- Safe internal root-relative targets and explicitly approved HTTPS external destinations.
- Permanent `301` redirects through the native Jyavani redirect hook.
- Request query-string preservation through Jyavani Core, with explicit consent for external forwarding.
- Atomic hit counters and last-hit timestamps.
- Enable, disable, delete, and reset-hit bulk actions.
- Save-time and runtime redirect-loop protection.
- Runtime protection against routes created after a redirect rule.
- Privacy-conscious 404 aggregation with a 10,000-path cap.
- English, Indonesian, and German dashboard copy.
- Native Jyavani permissions, CSRF protection, PRG notifications, and authorization audit events.

## Requirements

- Jyavani CMS 2.3.74 or newer.
- PHP 8.1 or newer.
- PDO MySQL, JSON, and mbstring extensions.
- MySQL 5.7 or a compatible MariaDB release.

## Installation

1. Build or download a flat plugin ZIP with `plugin.json` at the archive root.
2. Open the Jyavani Plugin Manager and upload the ZIP.
3. Activate Redirection.
4. Open **Tools > Redirection**.

The plugin initializes its own `jyr_redirects` and `jyr_404_paths` tables. No Core table is altered.

## Redirect Semantics

Jyavani Core 2.3.74 exposes unresolved redirects as permanent `301` responses and appends the incoming request query string. Redirection deliberately follows that contract instead of sending headers from inside a filter.

Source paths:

- May be entered as `old-section`, `old-section/`, `/old-section`, or `/old-section/`; all normalize to `/old-section/`.
- File-like paths such as `adam.html` normalize to `/adam.html` without a trailing slash.
- Match exact paths only and preserve letter case.
- Cannot include query strings or fragments.
- Cannot shadow active Jyavani routes.

Target URLs:

- May be internal and root-relative, such as `/new-section/`.
- Internal slashes are optional; `new-section` normalizes to `/new-section/`.
- May use an absolute HTTPS domain after both external-destination and query-forwarding consent are checked.
- A domain without a scheme, such as `google.com/video.mp4`, normalizes to `https://google.com/video.mp4` after consent.
- A dotted first segment is treated as a domain. Prefix it with `/`, such as `/video.mp4` or `/folder.name/video.mp4`, when it is an internal path.
- External URLs cannot contain credentials, custom ports, IP hosts, protocol-relative syntax, or unsafe control bytes.
- Absolute URLs for the current host are rejected; use a root-relative target so loop detection remains effective.
- May include an encoded query string or fragment.
- Internal targets cannot point back into a redirect cycle.
- Redirect chains longer than 16 hops are rejected.
- Jyavani Core appends the incoming request query string to every redirect target. External redirects therefore require an explicit acknowledgement that those values will leave the site.

## 404 Privacy

404 Insights stores only:

- normalized public path;
- aggregate hit count;
- first-seen timestamp;
- last-seen timestamp.

It does not store query strings, IP addresses, referrers, user agents, cookies, sessions, or request bodies. Dashboard, authentication, private, static, API, sitemap, and installer paths are excluded. New unique paths stop being recorded at 10,000 entries, while existing aggregates continue to increment.

## Uninstall

Jyavani can preserve plugin data during uninstall. Because inactive plugins cannot register cleanup callbacks in current Core releases, use **Data removal** on the Redirection dashboard before uninstalling when complete removal is required. The action removes only the two `jyr_` tables and schema-version setting, then deactivates Redirection.

## Development

Run the standalone contracts:

```bash
php tests/contract.php
php tests/security_contract.php
php tests/package_contract.php
```

Build and inspect a flat candidate package:

```bash
php tools/build-package.php /tmp/redirection-1.2.0.zip
```

The repository is the authoritative development source. Store publisher copies and installed copies are release or runtime derivatives.

## License

MIT License. See `LICENSE`.

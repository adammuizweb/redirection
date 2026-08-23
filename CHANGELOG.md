# Changelog

## 1.2.0 - 2026-08-23

- Accept source and internal target paths with omitted leading or trailing slashes.
- Preserve file-like sources and targets without a trailing slash.
- Normalize external domain shorthand such as `google.com/video.mp4` to HTTPS after explicit external consent.

## 1.1.0 - 2026-08-23

- Add explicit opt-in support for validated HTTPS external destinations.
- Require acknowledgement that Jyavani Core forwards incoming query strings to external targets.
- Keep external targets outside internal loop and redirect-chain resolution.
- Reject same-host absolute targets, numeric IP aliases, credentials, custom ports, and unsafe URL bytes.

## 1.0.0 - 2026-08-23

- Add exact unresolved-path redirects with safe internal targets.
- Add hit counters, enable/disable state, bulk actions, and loop detection.
- Add bounded, privacy-conscious 404 insights.
- Add Jyavani permission, CSRF, audit, localization, and package contracts.

# Changelog

## 1.3.1 - 2026-10-01

- Improve the 404 insight create-redirect action styling.
- Add persistent visibility controls for optional 404 insight columns.

## 1.3.0 - 2026-10-01

- Add bounded 10, 20, 50, 100, and 200-item page-size controls for redirects and 404 insights.
- Add first, previous, next, and last navigation while preserving filters and page size.

## 1.2.2 - 2026-10-01

- Improve the redirects and 404 insights dashboard for phone-sized screens.
- Present table rows as labeled mobile cards and enlarge form controls and action targets.

## 1.2.1 - 2026-09-10

- Allow exact redirect rules to intentionally override article, page, and Theme Template paths.
- Keep Core, authentication, static, collection, root-category, and other plugin routes protected.

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

# Changelog

> Maintained by `grip-bump-version.py`. Format: [version] — date _level_

## [1.17.1] - 2026-04-23  _patch_

- Fix: barcode scanner and label printing now work on all browsers. Use assetId camelCase to match client data model, and lazy-load ZXing-js fallback when BarcodeDetector is unavailable (desktop Linux/Windows Chrome, Firefox, older Safari). Clearer camera error messages.

## [1.17.0] - 2026-04-23  _minor_

- Barcode scanner + label printing: new Scan button and mobile FAB opens a full-screen camera overlay that reads Code 128/39/EAN/UPC/QR via the native BarcodeDetector API and toggles matching gear in/out on the active day. Unknown-on-day codes auto-add from master inventory as out. Manual-entry fallback input doubles as a keyboard-wedge target for USB/BT scanners. Haptic buzz + WebAudio beep on scan. New Print Labels action on Inventory renders a 3-across grid of Code 128 labels as pure SVG (no libraries) in a print-friendly popup. New S keyboard shortcut.

---

## [1.16.0] — 2026-04-22  _calibration_

**Retroactive version calibration.** The project had been shipping every change
as a patch bump (`1.0.58`, `1.0.59`, ... up through `1.1.0`) when many were
actually feature-bearing minor releases. With the new diff-aware version bumper
now in place, the full commit history was re-classified and the current version
rebased to reflect proper semver. No code changed in this bump — just the
version strings in `index.html` and `grip-version.php`.

Breakdown of the minor-worthy releases that got us here, oldest to newest:

- **1.1.0** — GitHub Actions auto-deploy workflow
- **1.2.0** — Drop Director input; auto-derive from top ATL contact + Production Co. autocomplete
- **1.3.0** — Production Companies feature (new CRUD page, nav tab, schema migration)
- **1.4.0** — Merge Contacts + Companies into unified Directory view with sub-tabs
- **1.5.0** — Directory redesign (segmented tabs, live search, contact chips, accent stripes)
- **1.6.0** — Directory sort controls, role/quick filters, bulk select/delete/assign
- **1.7.0** — Home visual hierarchy refresh (greeting, KPI tiles, Today/Upcoming cards)
- **1.8.0** — Jobs visual hierarchy refresh (iconified meta, grouped actions, tinted stats)
- **1.9.0** — Inventory visual hierarchy refresh (summary strip, category rail, hover actions)
- **1.10.0** — Settings visual hierarchy refresh (sticky save bar, iconified groups, Danger Zone)
- **1.11.0** — Users + Requests visual hierarchy refresh (role cards, status KPIs, status stripes)
- **1.12.0** — Calendar visual hierarchy refresh (segmented tabs, today indicator, card legend)
- **1.13.0** — Job Templates feature (`job_templates` table + REST API, template picker)
- **1.14.0** — Job Dashboard feature (Overview tab with summary, timeline, gear master, damage, activity)
- **1.15.0** — PDF cover page + browser Back button navigation
- **1.16.0** — Smart diff-aware version bumper (this tool)

Interspersed patches (not listed above) covered: mobile polish, theme audit,
deploy infrastructure iterations, email diagnostic improvements, email send
fix (HTML attachment path), and Gmail plaintext rendering fix.

No breaking changes have occurred since the 1.0.57 initial commit; major stays
at 1. The next **2.0.0** will be reserved for an actual breaking change —
major schema rework, API redesign, or similar.

---

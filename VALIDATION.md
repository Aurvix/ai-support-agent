# Release validation

Version **0.1.45** was checked locally on 19 November 2026. It is a distribution/documentation update to the preserved 0.1.44 implementation. Runtime source changes are limited to the version and public plugin metadata. GPL text and installation information are added to the installable package.

## Results

| Check | Result |
| --- | --- |
| PHP 8.1 parser / JavaScript syntax | PASS: 20 production PHP/JS files |
| JavaScript/static suites | PASS: all 16 suites, including 8 static assertions |
| Exact-ZIP native WordPress/WooCommerce matrix | PASS: 13 profiles in each of HPOS and CPT, 26 runs total |
| Matrix profiles | Core ownership/downloads, wire transport, notifications, diagnostics, multisite, live Inbox, conversations, public references, customization, catalog, administration, pilot, upgrade |
| Plugin ZIP | 25 files, 141,386 bytes; packaged source hashes match the tested manifest |

The fixture used Node.js 24.21.0, PHP 8.1.34, WordPress 7.1.4, WooCommerce 11.2.2 and SQLite Database Integration 3.1.3. The core profile exercises 340 native checks plus 12 download-handler cases per storage mode. HTTP/mail transports are intercepted and all accounts/orders/conversations are disposable synthetic fixtures. No real provider key is required.

Installable ZIP SHA-256:

```text
6481B0D597480B2C1187EC25795479009839D23FAD51FA1629A50E07504DE827
```

## Distribution boundaries

The public snapshot excludes original staging evidence, private logs, machine-specific configuration, credentials, cached runtimes and historic project conversation files. Public history starts from a reviewed export; the original project has no Git history to migrate. The legacy-upgrade fixture contains reviewed GPL source and is rebuilt locally, rather than distributing the original historical archive.

See [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for redistribution scope and [releases/v0.1.45](releases/v0.1.45) for the exact tested package. Local raw diagnostic reports remain outside the public repository.

## Reproduction and limits

Use `npm ci`, `npm test`, `npm run build`, the pinned official runtime archives and `npm run test:integration` as described in README. Reports are generated under ignored `outputs/`; build artifacts under ignored `dist/`. Only the explicitly versioned release ZIP is tracked.

This run did not install on a live website, change production/Gemini settings, call an external model or deliver notifications. Normal browser authentication, real provider quality/quota, external delivery, MCP-client interoperability, host/cron reliability, physical-device accessibility and site-specific business facts/file hosting are not certified by these tests. The public release is an early installable software distribution, not a live-store readiness certificate.

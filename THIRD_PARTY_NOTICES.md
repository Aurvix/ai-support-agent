# Licensing and redistribution inventory

The existing plugin declares GPL-2.0-or-later. That declaration is preserved; the standard unmodified GPL version2 text is included as LICENSE and ai-support-agent/LICENSE.txt. “Or later” remains the project licensing declaration; the license document itself is not rewritten.

## Shipped plugin

The PHP, JavaScript and CSS are the existing AI Support Agent project source. The avatar is an inline, project-created geometric SVG containing rectangles/circles/paths; it has no embedded external image/font or dependency. No third-party icon package, website screenshots, proprietary product artwork, downloaded font, WordPress/WooCommerce implementation, node_modules or model weights are bundled. Provider names/endpoints and Unicode symbols are references, not redistributed SDKs/media.

## Development-only dependencies

These are installed separately through package-lock.json, not vendored in Git or the installable plugin:

| Dependency | Version | Declared license |
| --- | --- | --- |
| @php-wasm/logger, node-8-1, progress, stream-compression, universal |3.1.62 |GPL-2.0-or-later |
| @php-wasm/util |3.1.62 |Installed LICENSE contains the standard GPLv2 text; implementation is not redistributed here |
| ini |4.1.2 |ISC |
| php-parser |3.2.5 |BSD-3-Clause |
| wasm-feature-detect |1.8.0 |Apache-2.0 |

The GPL text was copied verbatim from the installed WordPress Playground universal package's standard LICENSE, which explicitly permits copying the license document. Its upstream is [WordPress Playground](https://github.com/WordPress/wordpress-playground); the canonical text is [GNU GPLv2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

WordPress, WooCommerce and SQLite Database Integration fixture archives are separately downloaded from downloads.wordpress.org, with pinned SHA-256 checks. Their implementation archives/binaries are not tracked or shipped. The legacy0.1.7 AI Support Agent fixture contains this project's reviewed GPL source only; it is not WordPress/WooCommerce source or a customer database.

No current production configuration, customer material, staging log, model credential or old private Git history is part of this distribution.

# Security and private reporting

This is an early public release with isolated synthetic tests, not an independent security certification. Verify your site's access restrictions, provider configuration and file-hosting behavior before use.

Do not publish credentials, customer chats/exports, order identifiers, signed download links, private configuration or exploit details in a public issue. This repository does not promise a private reporting channel or a response SLA. If GitHub private vulnerability reporting is available on the repository, use it. Otherwise open a minimal issue asking the maintainer for a private route without disclosing sensitive details.

Reports should identify the version, affected feature, expected/actual result and a fictional reproduction. Contributions must retain server-side capability/nonce/ownership checks, per-turn source freshness, private-content exclusions and destination-bound credentials. Model/reference text must never become authorization for payments, refunds, account access or executable settings changes.

Current limitations include unverified real provider/client/delivery/host behavior and third-party membership/file-storage policies. Local bounded cleanup cannot promise erasure of provider copies or independent backups.

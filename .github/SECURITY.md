# Security Policy

Bootgly is a zero-dependency PHP framework — the entire core (`ABI → ACI → ADI → API →
CLI → WPI`) is implemented natively, with no third-party packages in the trust boundary.
That keeps the supply-chain surface small, but the framework still terminates raw TCP/TLS
traffic (HTTP/1.1, HTTP/2, WebSocket, TCP/UDP servers), so protocol- and application-level
hardening matters. This document describes what's supported, how to report a vulnerability,
and the audit history behind the current hardening.

## Supported Versions

Bootgly is pre-1.0 (`-beta` versioning). Only the **latest published `-beta` tag** receives
security fixes — there is no backport policy across minor versions before 1.0.

| Version | Supported |
| --- | --- |
| Latest `-beta` release | ✅ |
| Older `-beta` releases | ❌ |

## Reporting a Vulnerability

Report suspected vulnerabilities privately to **cybersec@bootgly.com** — do not open a
public GitHub issue for anything exploitable. Include:

- Affected component (layer + class, e.g. `WPI/Nodes/HTTP_Server_CLI/Decoders/Decoder_Chunked`)
- Reproduction steps or a PoC
- Impact you assess (DoS, info leak, bypass, RCE, ...)
- Version/commit tested against

We acknowledge reports within 48 hours and send progress updates while we investigate and
fix. Please give us reasonable time to ship a fix before any public disclosure.

## Disclosure Policy

Coordinated disclosure: we ask reporters not to disclose publicly until a fix is released,
or **90 days** from the initial report, whichever comes first. Once a fix ships, we credit
the reporter (unless anonymity is requested) in the release notes.

## Scope

**In scope:** the framework core (`bootgly/bootgly`) and the platform repositories
(`bootgly-console`, `bootgly-web`) — protocol decoders/encoders, routing, middlewares,
session/auth, database drivers, and any other native component in those repos.

**Out of scope:** `bootgly_website`, `bootgly_docs`, `bootgly_benchmarks`, `bootgly_awesome`,
and tooling repos — report bugs there as regular issues unless they lead back into the
framework core. Denial-of-service testing against shared infrastructure (the website,
benchmark hosts, CI) and social-engineering/physical attacks are always out of scope.

## Security Audit History

Two holistic, adversarial audits have run against the network-facing surface. Both are
closed — every finding is fixed and covered by a regression test in the corresponding test
suite (`.../tests/Security/*.test.php`).

### HTTP/1.1 — `HTTP_Server_CLI` (2026-06-11 → 2026-06-16)

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| F-1 | High | Request-line protocol-token validation gap → Host-allowlist/framing bypass | ✅ Fixed |
| F-2 | High | No connection concurrency ceiling → exhaustion DoS | ✅ Fixed |
| F-3 | High | Client-controlled proxy headers could override the trusted peer address | ✅ Fixed |
| F-4 | Medium | Rate limiting bypassable (key/window/cap weaknesses) | ✅ Fixed |
| F-5 | Medium | Compression enabled a BREACH oracle against the CSRF token | ✅ Fixed |
| F-6 | Medium | Chunked-body decoder timeout was a sliding window → slow-drip DoS | ✅ Fixed |
| F-7 | Medium | JSONP response served under the wrong `Content-Type` | ✅ Fixed |
| F-8 | Medium-Low | CORS reflected `Origin` without `Vary: Origin`; permissive defaults | ✅ Fixed |
| F-9 | Medium-Low | Session cookie `Secure`/`SameSite` inherited insecure `php.ini` defaults | ✅ Fixed |
| F-10 | Low-Medium | Upload temp files / SHM reservations could leak on worker crash | ✅ Fixed |
| F-11 | Low | `ETag`/`Compression` mutated every response, including errors | ✅ Fixed |
| F-12 | Low | View rendering relied solely on a single guard, no defense-in-depth | ✅ Fixed |

### HTTP/2 — `WPI/Modules/HTTP2` + `Decoder_HTTP2`/`Encoder_HTTP2` (2026-07-01)

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| S1 | High | HTTP/2 bypassed the HTTP/1.1 method allowlist | ✅ Fixed |
| S2 | Medium | HPACK-decoded names/values skipped control-character validation | ✅ Fixed |
| S3 | Medium | h2c prefix sniffing committed too early on a single byte | ✅ Fixed |
| S4 | Medium | File responses were fully materialized into memory | ✅ Fixed |
| S5 | Low | `:path`/pseudo-header validation was weaker than HTTP/1.1's | ✅ Fixed |
| S6 | Low | `Feeding` streaming contract wasn't actually invoked by the read loop | ✅ Fixed |

Validated against `h2spec v2.6.0` (145/146 — the one divergence is a documented,
intentionally tolerated shared-port case) plus the dedicated `tests/Security/` suite.

## Best Practices for Deployers

- Run behind TLS; enable `TrustedProxy` only for proxies you actually control, and keep it
  disabled otherwise so `$Request->address` stays authoritative.
- Configure `RateLimit` and connection caps for your traffic profile — the framework ships
  safe defaults, not infinite capacity.
- Keep session cookies on their framework-owned `Secure`/`HttpOnly` defaults; don't relax
  them via `php.ini`.
- Track the `-beta` release notes — pre-1.0 security fixes land as part of normal releases,
  not backports.

## Bug Bounty

No bug bounty program exists today. We credit reporters publicly (opt-in) and may introduce
a bounty program post-1.0.

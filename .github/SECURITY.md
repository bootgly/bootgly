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

Three holistic, adversarial audits have run against the network-facing surface. All three
are closed — every finding is fixed and covered by a regression test in the corresponding
test suite (`.../tests/Security/*.test.php`).

We publish audits only once their findings are remediated. An audit that is still being
worked is not listed here, and its findings are not described publicly until they ship as
fixed — the same coordinated-disclosure standard we ask of external reporters.

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

### Transport, HTTP/1.1, HTTP/2, TLS and AutoTLS — `HTTP_Server_CLI` + `TCP_Server_CLI` (2026-07-21 → 2026-07-25)

The broadest audit so far: the accept loop, both protocol decoders/encoders, route response
caching, deferred (Fiber) execution, the TLS handshake watcher, and the privileged AutoTLS
boot path. Every finding below was reproduced by a native PoC before any production code was
changed, and each PoC was retained as the regression that fails on the vulnerable code and
passes on the fix.

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| C1 | High | Abortive TCP close could throw from the accept loop and crash a worker | ✅ Fixed |
| C2 | High | Early route-cache hits could bypass custom authentication and admission middleware | ✅ Fixed |
| C3 | High | Deferred work could observe another request's mutable Route/Request context | ✅ Fixed |
| C4 | High | Multipart initial-boundary search retained the full preamble in memory | ✅ Fixed |
| C5 | High | Duplicate byte ranges created multi-million-fold response amplification | ✅ Fixed |
| C6 | High | Privileged AutoTLS recursive ownership handoff had a symlink TOCTOU | ✅ Fixed |
| M1 | Medium | Oversized chunk-size integer conversion became a terminal zero chunk → request smuggling | ✅ Fixed |
| M2 | Medium | Deferred HTTP/2 responses could lose their stream identity | ✅ Fixed |
| M3 | Medium | Ordinary HTTP/2 response backlogs lacked an aggregate budget | ✅ Fixed |
| M4 | Medium | Flow-stalled responded streams bypassed the HTTP/2 rapid-reset budget | ✅ Fixed |
| M5 | Medium | Successful TLS negotiation could leave a stale write watcher | ✅ Fixed |
| M6 | Medium | AutoTLS helper PID was outside authenticated project-control identity | ✅ Fixed |
| M7 | Medium | Delegated AutoTLS readiness trusted forgeable runtime-writable PID JSON | ✅ Fixed |
| M8 | Medium | Response header identity was case-sensitive and value validation was inconsistent | ✅ Fixed |
| N1 | Low | HTTP/1 field-name grammar was not fully validated | ✅ Fixed |
| N2 | Low | A complete HTTP/1 request head could bypass the nominal 16 KiB cap | ✅ Fixed |
| N3 | Low | Production header-scan memoization was not exercised by the native Test environment | ✅ Fixed |

Two of these are worth calling out for deployers. **M1** is a request-smuggling primitive
that needs no differential proxy in front — the server itself dispatched the request hidden
behind the overflowing chunk-size token. **C6** is a local privilege-escalation boundary: it
only applies when the server is started as root with AutoTLS, and it was validated by driving
the real ownership walker as UID 0 inside an unprivileged user namespace against a root-owned
canary outside the managed store.

Several findings closed a primary attack path while leaving a narrower, related surface
scoped out of that pass. Those remainders are recorded explicitly in the audit report rather
than silently absorbed, and they are carried into the follow-up audit.

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

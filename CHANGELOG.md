# Changelog

Changelog for Bootgly framework. All notable changes to this project will be documented in this file. Imported from ROADMAP.md.

## v0.14.3-beta ✅

> Focus: **Router negative cache pollution prevention — remove static cache promotion for catch-all misses**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Router` — removed unbounded promotion of attacker-controlled URLs into `staticCache['']` on catch-all misses; `MAX_NEGATIVE_CACHE` constant and `$negativeCacheCount` field removed; net +1.4% throughput improvement on catch-all 404 scenario
- ✅ HTTP Server CLI: Security regression test `15.01-router_catchall_negative_cache_pollution` — 500 unique miss URLs; vulnerable build reports 500/500 polluted entries, fixed build reports 0/500

---

## v0.14.2-beta ✅

> Focus: **Arbitrary file inclusion prevention via EXTR_SKIP in Template extract()**

### ABI — Abstract Bootable Interface

- ✅ Templates: `Template::render()` now passes `EXTR_SKIP` to `extract()`, preventing template variables from overwriting local scope variables (including `$__template__`) and closing arbitrary file inclusion via attacker-controlled variable names

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Response::render()` inherited fix — same EXTR_SKIP protection applied to all HTTP response template rendering
- ✅ HTTP Server CLI: Security regression test `14.01-response_render_extract_file_inclusion` covering file inclusion via variable override scenario

---

## v0.14.1-beta ✅

> Focus: **Session Set-Cookie deferred until mutation to prevent session fixation and DoS**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Session` — `Set-Cookie` emission deferred until session is actually mutated (`set`, `put`, `delete`, `pull`, `forget`, `flush`, `regenerate`); read-only access no longer emits a cookie, closing session fixation and API-probe DoS surface
- ✅ HTTP Server CLI: Security regression test `13.01-session_unconditional_set_cookie_on_read` covering read-only probe, write-triggers-cookie, and no-session-untouched scenarios

---

## v0.14.0-beta ✅

> Focus: **UDP Server CLI + UDP Client CLI interfaces**

### WPI — Web Programming Interface

- ✅ UDP Server CLI: New `UDP_Server_CLI` interface — UDP server with connection handling, router, commands, and packages
- ✅ UDP Client CLI: New `UDP_Client_CLI` interface — UDP client with connection handling, commands, and packages
- ✅ Connections: New `Peer` class for parsing peer strings (host + port) from connection addresses across TCP and UDP
- ✅ TCP + UDP: Renamed connection and data lifecycle hooks for clarity and consistency (across all interfaces)
- ✅ TCP + UDP: Renamed SSL transport configuration key from `ssl` to `secure` across all interfaces (HTTP_Server_CLI, TCP_Server_CLI, TCP_Client_CLI)
- ✅ HTTP Server CLI: Packages integration in decoders and encoders refactored for consistency with new UDP interfaces
- ✅ HTTP Server CLI: Added security regression test for `Response::upload()` path traversal guard with `File` instances
- ✅ HTTP Client CLI: Enhanced Demo with improved connection messages

### ACI — Abstract Common Interface

- ✅ Tests: `Results::$enabled` property controls output suppression when Agents run tests
- ✅ Tests: Index-based handler dispatch via `X-Bootgly-Test` header in E2E test execution

### API — Application Programming Interface

- ✅ Server: Initialized `key` property to prevent potential null reference
- ✅ State: Added ownership transfer method for state files

### CLI — Command Line Interface

- ✅ Status command: Removed unused version variable from output

### Bootgly

- ✅ Demo: Removed old monolithic Demo project (split into individual dedicated projects)
- ✅ Benchmark: Enhanced competitor normalization and metric reporting
- ✅ Process: Removed unnecessary logging from `Process` constructor during worker forking
- ✅ PHPStan: Fixed static analysis issues
- ✅ HTTPS Client CLI: Made URL argument required for startup
- ✅ .gitignore: Added context-mode folder exclusion

---

## v0.13.18-beta ✅

> Focus: **Multipart upload hardening for hidden filename and safe streaming writes**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Decoder_Downloading` now strips leading dots/spaces/tabs from multipart filenames (`ltrim`) to prevent hidden dotfile uploads (e.g. `.htaccess`)
- ✅ HTTP Server CLI: Sanitization fallback now enforces safe default filename (`upload`) when the sanitized name becomes empty
- ✅ HTTP Server CLI: Added guarded chunk writer path with explicit write-failure handling, periodic disk-space checks, and per-file size enforcement during streaming upload
- ✅ HTTP Server CLI: Security regression test `07.02-multipart_filename_leading_dot` validates rejection of leading-dot filename persistence in `$_FILES`
- ✅ HTTP Server CLI: Security test index cleanup keeps multipart hardening coverage deterministic across suite runs

---

## v0.13.17-beta ✅

> Focus: **Decoder L1 cache hardening against one-shot key churn DoS**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Decoder_` L1 cache no longer admits query-bearing targets (`?` in request-target), reducing one-shot attacker key churn admission
- ✅ HTTP Server CLI: L1 cache now performs LRU touch on hit (remove + reinsert key) and evicts the oldest key with `array_key_first` when capacity (`512`) is exceeded
- ✅ HTTP Server CLI: Cache lookup eligibility no longer depends on `Request::$maxBodySize`; `<= 2048` remains the fixed L1 candidate cap
- ✅ HTTP Server CLI: Security regression coverage expanded with `03.02-decoder_cache_one_shot_key_eviction_dos` and supporting suite-index updates

---


## v0.13.16-beta ✅

> Focus: **Redirect and file-send path hardening in HTTP Server CLI**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Response::redirect()` now rejects control bytes (`\x00-\x1F`, `\x7F`), backslashes, dangerous URI schemes (`javascript:`, `data:`, `vbscript:`, `file:`), and non-local redirect forms when `allowExternal` is `false`
- ✅ HTTP Server CLI: Added security regression test `13.01-open_redirect_backslash_bypass` covering protocol-relative and backslash-based redirect bypass payloads
- ✅ HTTP Server CLI: `Response::send()` received an additional jail check to block file-require bypass attempts outside allowed view/project boundaries
- ✅ HTTP Server CLI: Added security regression test `14.01-response_send_file_require_bypasses_view_jail`
- ✅ HTTP Server CLI: Test suite stability improvements for security FIFO ordering compatibility routes

---

## v0.13.14-beta ✅

> Focus: **BodyParser body-size limit enforced at decode time**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `BodyParser::process()` pushes `$this->maxSize` into `Request::$maxBodySize` (idempotent one-way ratchet) — oversized bodies are now rejected at decode time before TCP payload is buffered
- ✅ HTTP Server CLI: `Decoder_::decode()` L1 cache skips cache hits when `$size > Request::$maxBodySize` — decode-time gate always fires after a `BodyParser` push
- ✅ HTTP Server CLI: `Request::decode()` size check compares `$content_length` (body only) against `$maxBodySize` instead of `$length` (header + body) — fixes false positives for small-body / large-header requests
- ✅ HTTP Server CLI: Security test `11.01-bodyparser_limit_bypass_decode_time` — two-connection PoC proves the decoder gate is lowered after priming

---

## v0.13.13-beta ✅

> Focus: **Host-header allowlist enforcement**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: New `Request::$allowedHosts` static property — when non-empty, requests with an unrecognized `Host` header are rejected `400 Bad Request` at decode time (blocks cache poisoning and password-reset poisoning in multi-tenant apps)
- ✅ HTTP Server CLI: Wildcard prefix `*.example.com` matches any single-label subdomain; IPv6 bracketed literals handled correctly; empty list (default) disables enforcement
- ✅ HTTP Server CLI: Security test `10.01-host_header_allowlist_spoofing`

### Bootgly

- ✅ License: Updated copyright notice to `2023-present Bootgly`

---

## v0.13.12-beta ✅

> Focus: **Reject `Expect: 100-continue` with chunked TE and enforce Content-Length before body receipt**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: Reject `Expect: 100-continue` + `Transfer-Encoding: chunked` with `417 Expectation Failed` — prevents unauthenticated 10 MB stream abuse
- ✅ HTTP Server CLI: Reject oversized `Content-Length` with `Expect: 100-continue` with `413 Content Too Large` before body is received
- ✅ HTTP Server CLI: Security tests `9.01-expect_100_continue_with_te_chunked`, `9.02-expect_100_continue_with_oversized_content_length`

---

## v0.13.11-beta ✅

> Focus: **Path traversal sibling-prefix bypass in Response + shallow-clone sub-object bleed in Decoder_ cache**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Response::process()` and `upload()` — base-path `str_starts_with` checks now append `DIRECTORY_SEPARATOR`, closing the sibling-prefix bypass (e.g. `projects_malicious/`)
- ✅ HTTP Server CLI: `Decoder_` request cache — auth fields (`authUsername`, `authPassword`, `_authorizationHeader`) reinitialized on cache hit, preventing cross-connection credential bleed
- ✅ HTTP Server CLI: Security tests `7.01-response_path_traversal_sibling_prefix_bypass`, `8.01-decoder_cache_shallow_clone_subobject_bleed`

---

## v0.13.10-beta ✅

> Focus: **TrustedProxy — correct real client IP resolution from multi-hop XFF chains**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `TrustedProxy` middleware — `X-Forwarded-For` is now walked right-to-left, skipping trusted IPs; the first untrusted entry is the real client IP (previously `$ips[0]` was fully attacker-controlled)
- ✅ HTTP Server CLI: Multi-hop chain support — requests traversing N trusted hops are correctly resolved

---

## v0.13.9-beta ✅

> Focus: **Multipart boundary validation per RFC 7578 — injection and algorithmic DoS prevention**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: Multipart boundary validated against RFC 7578 `token` ABNF — injected quotes, semicolons, and non-token characters rejected
- ✅ HTTP Server CLI: Boundary length capped at 70 chars (RFC 2046 §5.1.1) — prevents catastrophic `strpos` scans (algorithmic DoS)
- ✅ HTTP Server CLI: Security test `6.01-multipart_boundary_injection_and_oversize`

---

## v0.13.8-beta ✅

> Focus: **Chunked Transfer-Encoding decoder hardening — CRLF validation and hex chunk-size sanitization**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Decoder_Chunked` — explicitly validates `\r\n` terminators after each chunk data segment; invalid terminators rejected with `400 Bad Request`
- ✅ HTTP Server CLI: `Decoder_Chunked` — chunk size lines validated against `/^[0-9a-fA-F]+$/`; previously `hexdec()` silently misinterpreted `0x0`, `-1`, `+7`, `0e0`, etc.
- ✅ HTTP Server CLI: Security test `5.01-chunked_decoder_blind_crlf_consumption`

---

## v0.13.7-beta ✅

> Focus: **Enhanced Content-Length validation to prevent request smuggling**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: Strict `Content-Length` parsing — rejects non-numeric values, leading zeros, whitespace padding, signed values (`+`/`-`), hex notation and other bypass patterns
- ✅ HTTP Server CLI: Security test `3.02-content_length_strict_parse_bypass`

---

## v0.13.6-beta ✅

> Focus: **HMAC validation for session file handling**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Session/Handlers/File` — session files now signed with HMAC-SHA256 on write; tampered or unsigned files rejected on read, preventing unserialization forgery
- ✅ HTTP Server CLI: Security test `4.01-session_file_unserialize_forgery`

---

## v0.13.5-beta ✅

> Focus: **Reject negative Content-Length values**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Request::decode()` — negative `Content-Length` values now rejected at parse time
- ✅ HTTP Server CLI: Security test `3.01-content_length_negative_accepted`

---

## v0.13.4-beta ✅

> Focus: **Prevent cross-connection state bleed via decoder cache shared Request instances**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Decoder_` cache — each connection now receives a unique `Request` instance; shared object references across connections eliminated
- ✅ HTTP Server CLI: Security test `1.04-decoder_cache_shared_request_across_connections`

---

## v0.13.3-beta ✅

> Focus: **Decoder state isolation across connections (static → instance properties)**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Decoder_Chunked`, `Decoder_Downloading`, `Decoder_Waiting` — all state moved from `static` to instance scope; decoders instantiated per-connection
- ✅ HTTP Server CLI: `Encoder_` and `TCP_Server_CLI/Packages` updated for instance-scoped decoders
- ✅ HTTP Client CLI: `TCP_Client_CLI/Packages` updated for instance-scoped decoders
- ✅ HTTP Server CLI: Security tests for cross-connection state isolation (chunked, downloading, waiting decoders)

---

## v0.13.2-beta ✅

> Focus: **Performance optimizations and security hardening for HTTP Server**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: Prevent HTTP response splitting (CRLF injection) in response headers
- ✅ HTTP Server CLI: Memory exhaustion guard in HTTP body decoder (`Decoder_Waiting`)
- ✅ HTTP Server CLI: `redirect()` now blocks external URLs by default (open redirect prevention); new `$allowExternal` parameter
- ✅ HTTP Server CLI: Caching for URI-derived Request properties (`path`, `query`, `queries`)
- ✅ HTTP Server CLI: Optimized `Content-Length` calculation using `strlen` on raw body data
- ✅ HTTP Server CLI: Clean up static state between requests to prevent cross-request leakage
- ✅ HTTP Server CLI: Update Request properties on package change for accurate connection details

### ABI — Abstract Bootable Interface

- ✅ Optimized error handling and caching logic in `Errors` class

### ACI — Abstract Common Interface

- ✅ Slug function handles `null` values; slug normalization for competitor names in `Configs`

### Bootgly

- ✅ Simplified getters for `length` and `chunked` properties in `Body` class

---

## v0.13.1-beta ✅

> Focus: **HTTP Client CLI performance optimization (+29.6% throughput)**

### WPI — Web Programming Interface

- ✅ HTTP Client CLI: Encoder cache — avoids re-encoding identical requests
- ✅ HTTP Client CLI: Decoder cache for non-HEAD responses
- ✅ HTTP Client CLI: `Request` object reuse via `cachedRequest` when URI/method match
- ✅ HTTP Client CLI: Allocation-free `Response->reset()` with in-place `Header->reset()` / `Body->reset()`
- ✅ HTTP Client CLI: Throughput improved from 438K → 568K req/s (+29.6%); gap vs raw TCP Client narrowed from ~30% to ~6%
- ✅ HTTP Client CLI: 11 new `CacheIsolation` E2E tests (URI, method, status, headers, body isolation)

### Bootgly

- ✅ README: Clarified required PHP packages in dependencies section

---

## v0.13.0-beta

> Focus: **HTTP Client CLI + Linter**

### WPI — Web Programming Interface

- � HTTP Client CLI (`WPI/Nodes/HTTP_Client_CLI`)
  - ✅ GET, POST, PUT, DELETE, PATCH, HEAD, OPTIONS
  - ✅ RFC 9112-compliant response decoding (chunked, content-length, close-delimited)
  - ✅ 100-Continue two-phase request (headers-first → body on server acceptance)
  - ✅ 1xx informational response handling
  - ✅ Request body encoding: raw, JSON, form-urlencoded
  - ✅ Multi-value response headers
  - ✅ OWS (optional whitespace) trimming per RFC 7230
  - ✅ Keep-alive connection reuse (automatic `Connection: keep-alive`)
  - ✅ Request pipelining (queue multiple requests per connection)
  - ✅ Batch mode: `batch()` + multiple `request()` + `drain()`
  - ✅ Event-driven / async mode via `on()` hooks with per-socket request tracking
  - ✅ Multi-worker load generation (fork support)
  - ✅ Benchmark runner (HTTP_Client) with latency and req/s reporting
  - ✅ SSL/TLS support
  - ✅ Redirects (automatic follow up to configurable limit)
  - ✅ Connection timeouts
  - ✅ Retries

### Bootgly

- ✅ Linter: Import code style checker/fixer (`bootgly lint imports [path] [--fix] [--dry-run]`)
  - ✅ CLI command (`Bootgly/commands/LintCommand.php`)
  - ✅ Analyzer (`ABI/Syntax/Imports/Analyzer.php`) — tokenizes PHP via `token_get_all()`
  - ✅ Formatter (`ABI/Syntax/Imports/Formatter.php`) — auto-fix engine
  - ✅ Builtins registry (`ABI/Syntax/Builtins.php`) — PHP built-in functions, constants and classes
  - ✅ Token navigation subclass (`ABI/Syntax/Imports/Analyzer/Tokens.php`)
  - ✅ Issue detection:
    - ✅ Missing imports (functions, constants, classes)
    - ✅ Backslash-prefixed FQN in body (`\Foo\Bar` → explicit `use` import)
    - ✅ Wrong import order (use const → use function → use class)
    - ✅ Global imports not before namespaced
    - ✅ Non-alphabetical imports within same group
  - ✅ Auto-fix (`--fix`):
    - ✅ 6-bucket sorting (const global/namespaced, function global/namespaced, class global/namespaced)
    - ✅ Backslash prefix removal from body
    - ✅ Missing import insertion
    - ✅ `php -l` syntax validation before writing
    - ✅ Correct spacing for files with no existing `use` statements
  - ✅ Dry-run mode (`--dry-run`)
  - ✅ AI agent output (JSON report with structured issues)
  - ✅ Comma-separated `use` parsing (grouped and non-grouped)
  - ✅ Multi-namespace file detection (skips files with >1 namespace)
  - ✅ Local function tracking (avoids false positives on locally-defined functions)

#### Verifications

- [x] HTTP Client sends/receives GET, POST, PUT, DELETE, PATCH, HEAD, OPTIONS
- [x] HTTP Client handles chunked transfer-encoding (RFC 9112 §7.1)
- [x] HTTP Client handles 100-continue and other 1xx informational responses
- [x] HTTP Client reuses connections via keep-alive
- [x] HTTP Client supports pipelining and batch request mode
- [x] HTTP Client supports async/event-driven mode with `on()` hooks
- [x] Static analysis — PHPStan level 9
- [x] HTTP Client follows redirects up to configurable limit
- [x] HTTP Client respects timeout settings
- [x] HTTP Client retries failed requests
- [x] SSL/TLS connections work with valid certificates
- [x] Static analysis — PHPStan level 9
- [x] Code style — Linter: imports (check + auto-fix)

---

## v0.12.0-beta ✅

> Focus: **Router improvements + HTTP/1.1 compliance**

### WPI — Web Programming Interface 📋

- ✅ Router improvements
  - ✅ Route caching for performance
  - ✅ Regex validation for route params (`:<param><type>` inline syntax — `int`, `alpha`, `alphanum`, `slug`, `uuid`; compile-time expansion, zero runtime cost)
  - ✅ Catch-all params fix (named catch-all `/:query*` → `$this->Params->query` captures rest of URL including `/`; 2 regression tests — single and multi-segment)
- ✅ HTTP/1.1 Compliance (RFC 9110-9112)
  - ✅ `Transfer-Encoding: chunked` decoding on requests (RFC 9112 §7.1) — **CRITICAL**
    - ✅ Chunked body decoder (`<size>\r\n<data>\r\n ... 0\r\n\r\n`)
    - ✅ New `Decoder_Chunked` for incremental chunk reassembly
    - ✅ Reject `Transfer-Encoding` + `Content-Length` conflict (RFC 9112 §6.3)
  - ✅ `Expect: 100-continue` handling (RFC 9110 §10.1.1)
    - ✅ Send `100 Continue` interim response before body read
    - ✅ Return `417 Expectation Failed` for unknown expectations
  - ✅ `Connection` header management (RFC 9112 §9.3)
    - ✅ Honor `Connection: close` from client — close after response
    - ✅ Send `Connection: close` in response when server initiates close
    - ✅ HTTP/1.0 defaults to close unless `Connection: keep-alive`
  - ✅ HEAD response body suppression (RFC 9110 §9.3.2)
    - ✅ Send headers (including `Content-Length`) but omit body in `Raw::encode()`
  - ✅ Mandatory `Host` header validation (RFC 9112 §3.2)
    - ✅ Return `400 Bad Request` if `Host` header missing in HTTP/1.1 request
  - ✅ HTTP/1.0 backward compatibility (RFC 9110 §2.5)
    - ✅ Respond with `HTTP/1.0` status-line for 1.0 clients
    - ✅ Disable chunked Transfer-Encoding for HTTP/1.0 responses
  - ✅ `Allow` header in 405 responses (RFC 9110 §15.5.6)
  - ✅ `TRACE` / `CONNECT` → `501 Not Implemented` instead of `405` (RFC 9110 §9.3.8, §9.3.6)
  - ✅ `414 URI Too Long` for excessive request-target (RFC 9112 §3)
  - ⭕️ Trailer headers support in chunked responses (RFC 9112 §7.1.2)

#### Verifications

- [x] Router regex params reject invalid input (10 regression tests — valid/invalid per constraint type)
- [x] Catch-all routes match nested paths correctly
- [x] Chunked request body decoded correctly (single chunk, multi-chunk)
- [x] `Transfer-Encoding` + `Content-Length` conflict returns 400
- [x] `Expect: 100-continue` triggers 100 before body read
- [x] Unknown `Expect` value returns 417
- [x] `Connection: close` from client closes connection after response
- [-] HTTP/1.0 request closes connection by default (not testable in test mode — Encoder_Testing skips closeAfterWrite)
- [x] HEAD response has correct headers but empty body
- [x] Missing `Host` header in HTTP/1.1 returns 400
- [x] `TRACE` and `CONNECT` return 501
- [x] 405 response includes `Allow` header
- [x] URI exceeding limit returns 414
- [x] Static analysis — PHPStan level 9
- [x] Code style — Bootgly conventions / rules

---

## v0.11.0-beta ✅

> Focus: **Fiber Scheduler (Deferred Responses) + Streaming Decoder + Project API v2 + CLI improvements**

### ACI — Abstract Common Interface ✅

- ✅ Tests: `Specification` constructor refactored
  - ✅ `request` parameter made optional (`null|Closure`), mutually exclusive with `requests`
  - ✅ `InvalidArgumentException` validation for `request`/`requests` mutual exclusivity

### API — Application Programming Interface ✅

- ✅ Project API v2 refactor
  - ✅ `{folder_name}.project.php` boot file convention (was `WPI.project.php`/`CLI.project.php`)
  - ✅ Centralized interface index files (`WPI.projects.php`, `CLI.projects.php`)
  - ✅ Removed `projects/@.php` default config and default project concept
  - ✅ `Modes` enum moved from `WPI\Endpoints\Servers\Modes` to `API\Endpoints\Server\Modes`
- ✅ `ProjectCommand` v2 refactor (`Bootgly/commands/ProjectCommand.php`)
  - ✅ Bidirectional argument order (`project <name> <subcommand>` ↔ `project <subcommand> <name>`)
  - ✅ Removed `set` subcommand
  - ✅ Multi-instance lifecycle support (`locateAll()` — stop/show handle all instances)
  - ✅ `resolve()` — resolves project directory path with user-friendly tips
  - ✅ `discover()` — index-based discovery from `{Interface}.projects.php`
  - ✅ `help()` — rewritten with subcommand usage, examples, and hints

### CLI — Command Line Interface ✅

- ✅ CLI Commands Middleware system (`CLI/Commands/Middleware`)
  - ✅ `VersionFooterMiddleware` — renders Bootgly/PHP version footer for built-in commands
- ✅ `SetupCommand` v2
  - ✅ Wrapper script instead of symlink (better `sudo` support)
  - ✅ `--uninstall` option
  - ✅ `--capabilities` option (`CAP_NET_BIND_SERVICE` for privileged ports without root)
  - ✅ Alert-based output
- ✅ `HelpCommand` refactor
  - ✅ Error message moved to top with `Alert` component
  - ✅ Version footer extracted to `VersionFooterMiddleware`

### WPI — Web Programming Interface ✅

- ✅ HTTP Server CLI — Deferred Response system (Fiber-based async)
  - ✅ `Response::defer(Closure $work)` — create Fiber for async work
  - ✅ `Response::wait(mixed $value = null)` — suspend control (tick-based or I/O-aware via `stream_select`)
  - ✅ `Response::bind(Packages $Package, mixed $Socket)` — inject context for deferred sending
  - ✅ `$Response->deferred` property + Fiber internal state
  - ✅ Deferred state reset in `reset()`
- ✅ Request Body streaming decoder (multipart/form-data → disk)
  - ✅ `$Request->Body->streaming` property
  - ✅ `$Request->download()` — streaming multipart decoder (writes files directly to disk)
- ✅ HTTP Server CLI `on()` lifecycle hooks
  - ✅ `started` callback (after server binds and starts listening)
  - ✅ `stopped` callback (after graceful shutdown)
- ✅ HTTPS Server CLI project (`projects/HTTPS_Server_CLI/`)
  - ✅ SSL/TLS support (TLSv1.2 + TLSv1.3) via `configure(secure: [...])`
  - ✅ Privilege drop via `configure(user: 'www-data')`
- ✅ `BOOTGLY_PROJECT` validation guards in Response (`throw Error` when not defined)
- ✅ Code style cleanup — removed `\` prefixes from global function calls in Response/Header

### Bootgly ✅

- ✅ Projects renamed from interface convention to folder-name convention
  - ✅ `WPI.project.php` → `HTTP_Server_CLI.project.php`
  - ✅ New `HTTPS_Server_CLI/HTTPS_Server_CLI.project.php`
  - ✅ New `TCP_Server_CLI/TCP_Server_CLI.project.php`
  - ✅ New `TCP_Client_CLI/TCP_Client_CLI.project.php`
  - ✅ New `Demo_CLI/Demo_CLI.project.php`
- ✅ New SAPI handler examples
  - ✅ `HTTP_Server_CLI-scheduled.SAPI.php` — deferred vs blocking comparison routes
  - ✅ `HTTP_Server_CLI-download.SAPI.php` — streaming upload handler
  - ✅ `HTTP_Server_CLI-middlewares.SAPI.php` — middleware demo handler
- ✅ `PLAN.md` — Fiber Scheduler PoC planning document

#### Verifications ✅

- [x] Deferred response returns correct body (tick-based)
- [x] Deferred concurrent requests maintain state isolation
- [x] Deferred I/O-aware scheduling resumes on stream readiness
- [x] Deferred hybrid (tick + I/O phases) works correctly
- [x] Deferred HTTP request sends async external call (example.com)
- [x] Deferred ordering: fast response arrives before deferred completes (non-blocking proof)
- [x] Streaming decoder: 1 file, 0 fields (basic streaming)
- [x] Streaming decoder: 1 file, 1 field (mixed parts)
- [x] Streaming decoder: 2 files, 1 field (file-field-file order)
- [x] Streaming decoder: 0 files, 2 fields (multipart fields only)
- [x] Streaming decoder: 3 files, 0 fields (multiple files)
- [x] Streaming decoder: 1 file, 2 fields (fields before file)
- [x] Streaming decoder: 1 empty file (0 bytes content)
- [x] Sequential tests: `request`/`requests` mutual exclusivity enforced
- [x] Static analysis — PHPStan level 9
- [x] Code style — Bootgly conventions / rules

---

## v0.10.0-beta ✅

> Focus: **Project API + CLI Commands refactor + HTTP Server improvements**

### API — Application Programming Interface ✅

- ✅ Project API (`API/Projects/Project`)
  - ✅ Declarative `Project` class (name, description, version, author, boot Closure)
  - ✅ `boot()` method invokes the boot Closure with arguments and options
  - ✅ `*.project.php` file convention (`WPI.project.php`, `CLI.project.php`)
  - ✅ Platform fallback suffixes (`Web.project.php`, `Console.project.php`)
  - ✅ Simplified `projects/@.php` registry (`['default' => 'HTTP_Server_CLI']`)
- ✅ `ProjectCommand` CLI command (`Bootgly/commands/ProjectCommand.php`)
  - ✅ `list` — discover and list all projects with interfaces and `[default]` marker
  - ✅ `set` — set project properties (metadata) (`--default` option)
  - ✅ `run` — boot a project by name or default (`--CLI`, `--WPI` filters)
  - ✅ `info` — show detailed project properties (metadata) in a Fieldset
  - ✅ `help` — display subcommand usage
  - ✅ `discover()` — glob-based project discovery with interface/platform suffixes
  - ✅ `get()` — load project properties (metadata) from Project object

### CLI — Command Line Interface ✅

- ✅ Commands refactored from `projects/Bootgly/CLI/commands/` to `Bootgly/commands/` (framework-level)
  - ✅ Moved commands registry: `Bootgly/commands/@.php`
  - ✅ `DemoCommand` — run interactive CLI demos
  - ✅ `SetupCommand` — install Bootgly CLI globally (`/usr/local/bin`)
  - ✅ `BootCommand` — boot resource directories for consumer projects
  - ✅ `TestCommand` — run Bootgly test suites
  - ✅ `HelpCommand` — display global help with banner, commands, options, usage
  - ✅ `ProjectCommand` — manage projects (list, set, run, info)
- ✅ Removed `ServeCommand` (replaced by `project start --WPI`)

### WPI — Web Programming Interface ✅

- ✅ HTTP Server CLI improvements
  - ✅ `handle(Closure $Handler)` — fluent method for setting request handler with auto `Middlewares` init
  - ✅ Default server mode changed from `Modes::Monitor` to `Modes::Daemon`
  - ✅ Removed legacy `SAPI::$production` / `SAPI::boot()` from default boot case
- ✅ Response `reset()` method — reset response state (headers, body, status) between requests
- ✅ Encoder pipeline refactor (`Encoder_.php`, `Encoder_Testing.php`)
  - ✅ Generator-based routing resolved inside the middleware pipeline (not after)
  - ✅ Proper `$Result instanceof Response` handling after pipeline
- ✅ Router middleware reset per request (`$this->middlewares = []` in `routing()`)

### Bootgly ✅

- ✅ Projects restructured as self-contained directories with `*.project.php` boot files
  - ✅ `projects/Demo/HTTP_Server_CLI/` — HTTP server demo with static/dynamic routing and catch-all 404
  - ✅ `projects/TCP_Server_CLI/` — Raw TCP server with configurable workers
  - ✅ `projects/TCP_Client_CLI/` — TCP client benchmark (10s write/read stress test)
  - ✅ `projects/Demo_CLI/` — Interactive CLI demo for terminal components (22 demos)
- ✅ Scripts refactored — `http-server-cli`, `tcp-server-cli`, `tcp-client-cli` removed (replaced by projects)
- ✅ New `benchmark` script with multi-case support (Bootgly vs competitors, wrk-based, 6 scenarios)(private)
- ✅ Removed `composer.json` `scripts.serve` section (replaced by `project start`)

#### Verifications ✅

- [x] Project `list` discovers CLI + WPI projects and shows interfaces
- [x] Project `set --default` persists to `projects/@.php`
- [x] Project `run` boots default or named project
- [x] Project `info` displays metadata Fieldset
- [x] HTTP Server `handle()` initializes Middlewares and sets Handler
- [x] Response `reset()` clears state between requests
- [x] Generator routing works inside middleware pipeline
- [x] Router middlewares reset between requests (no leaking)
- [x] Static analysis — PHPStan level 9
- [x] Code style — Bootgly conventions / rules

---

## v0.9.0-beta ✅

> Focus: **new Test definition class + Middleware Pipeline**

### ACI — Abstract Common Interface ✅

- ✅ Tests: new Test definition class (`Specification` used in `*.test.php` with `Separator` value object)

### API — Application Programming Interface ✅

- ✅ Middleware interface (`API/Server/Middleware`)
  - ✅ `process (object $Request, object $Response, Closure $next): object`
  - ✅ Interface-only (one-way policy — no Closure middlewares)
- ✅ Middleware pipeline executor (`API/Server/Middlewares`)
  - ✅ Onion pattern via array reduction (fold right)
  - ✅ `pipe()`, `prepend()`, `append()` registration methods
  - ✅ `process()` execution with handler as innermost Closure
- ✅ Handler resolver (`API/Server/Handlers`)
  - ✅ Adapter: wrap `SAPI::$Handler` as pipeline-compatible Closure
- ✅ Integration in `Encoder_.php` and `Encoder_Testing.php` (wrap `SAPI::$Handler` call with pipeline)
- ✅ Middleware registration API
  - ✅ Global: `$Middlewares->pipe()` in SAPI bootstrap
  - ✅ Per-route group: `$Router->intercept()` inside nested routes
  - ✅ Per-route: `$Router->route(..., middlewares: [])` parameter
- ✅ Test middleware support in `SAPI::boot()` (per-test `'middlewares'` key)

### WPI — Web Programming Interface ✅

- ✅ Built-in middlewares (`WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/`)
  - ✅ CORS (preflight, origin validation, headers)
  - ✅ RateLimit (in-memory counters, per-worker, file persist on shutdown)
  - ✅ BodyParser (max size validation, Content-Length checking)
  - ✅ Compression (gzip/deflate, opt-in via middleware)
  - ✅ ETag (HTTP caching with If-None-Match, weak/strong)
  - ✅ SecureHeaders (X-Frame-Options, CSP, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)
  - ✅ RequestId (X-Request-Id UUID v4 header)
  - ✅ TrustedProxy (resolve real IP behind load balancer, X-Forwarded-For, X-Real-IP, X-Forwarded-Proto)

### Bootgly ✅

#### Verifications ✅

- [x] Middleware pipeline executes in correct onion order (before → handler → after)
- [x] Global middlewares run for every request
- [x] Per-route middlewares run only on matched routes
- [x] Nested route group middlewares execute after match, before handler
- [x] Short-circuit works (e.g., RateLimit returns 429 without calling next)
- [x] CORS preflight returns 204 without hitting handler
- [ ] RateLimit in-memory counters persist/restore on shutdown/boot
- [x] Static analysis — PHPStan level 9
- [x] Code style — Bootgly conventions / rules
- [x] API Server pipeline unit tests (6 tests — Advanced API)
- [x] WPI middleware unit tests with mock (8 tests — Advanced API)
- [x] HTTP Server CLI real integration tests (12 tests — all 8 middlewares)

---

## v0.8.0-beta ✅

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: Session subsystem (Session, Handler, Handling, Handlers, File)
- ✅ HTTP Server CLI: Cookies refactor
- ✅ HTTP Server CLI: Request `$scheme` from TCP SSL
- ✅ TCP Server CLI: Git Hooks test support
- ✅ Remove legacy HTTP_Server_ nodes

### Bootgly

- ✅ PHPStan level 9 — zero errors across all modules (ABI, ACI, ADI, API, CLI, WPI)
- ✅ CI: PHP 8.4 + Ubuntu 24.04
- ✅ Pre-commit hook: `bootgly test` gate
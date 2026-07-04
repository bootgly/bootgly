# Changelog

Changelog for Bootgly framework. All notable changes to this project will be documented in this file. Imported from ROADMAP.md.

## v0.19.1-beta ✅

> Focus: **HTTP Server CLI performance — persistent Fiber pool, route response cache, DBAL hot path**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: persistent Fiber pool for deferred responses — `defer()` reuses parked worker Fibers instead of constructing one per request (`/db` +84%, `/fortunes` +73% in TechEmpower loads)
- ✅ HTTP Server CLI: route response cache — `route(..., cache: ['TTL' => seconds])` serves cached wire responses with per-second `Date` patching (up to 10× on cacheable routes)
- ✅ HTTP Server CLI: Database response resource hot path — prototype clone in `provide()`, `fork()` via `clone`, dropped lazy `Scope` get hook
- ✅ HTTP Server CLI: fix stray break line in `VersionFooterMiddleware`

### ADI — Abstract Data Interface

- ✅ PostgreSQL Decoder: hot-type fast path for `DataRow`/`RowDescription` messages (fewer allocations per row)

---

## v0.19.0-beta ✅

> Focus: **WebSocket Server + WebSocket Client + HTTP/2 Server**

### WPI — Web Programming Interface

- ✅ WebSocket Server CLI (`WPI/Nodes/WS_Server_CLI`)
  - ✅ Handshake, frames, opcodes
  - ✅ Channels / rooms
  - ✅ Broadcasting (same-worker + cross-worker via per-worker datagram relay)
  - ✅ Ping/pong heartbeat
  - ✅ Auth handshake middleware (reuses v0.15 Authentication guards) + Origin gate (`HandshakeRequested`)
  - ✅ permessage-deflate (RFC 7692, ext-zlib)
  - ✅ Streaming UTF-8 validation + outbound fragmentation
  - ✅ wss:// (TLS)
- ✅ WebSocket Client CLI (`WPI/Nodes/WS_Client_CLI`) — wire-compatible with `WS_Server_CLI` (verified via live E2E + Autobahn 1-7/12/13 = 0 FAILED) — committed + pushed to main (137f54c6)
  - ✅ Self-contained codec duplicated in the client (own `Message/Frame` +masking, `Message/UTF8`, `Handshake`, `Message` DTO, inline permessage-deflate) — `WS_Server_CLI` NOT modified; the alpha-order rule (Client < Server) forbids importing the Server subtree, so the codec is duplicated by design
  - ✅ `WS_Client_CLI extends TCP_Client_CLI implements WS, WS\Client` (mirrors `HTTP_Client_CLI`) + `WS/Client.php` marker
  - ✅ Client handshake — generate `Sec-WebSocket-Key`, send upgrade GET, verify `101` + `Sec-WebSocket-Accept`
  - ✅ Frames, opcodes — outbound frames masked (RFC 6455 §5.1); inbound unmasked
  - ✅ Fragmentation (inbound reassembly + outbound `send(fragment:)`)
  - ✅ Ping/pong heartbeat (auto-pong to server ping + client-initiated ping)
  - ✅ permessage-deflate (RFC 7692, ext-zlib) — client offer / negotiate
  - ✅ Streaming UTF-8 validation (JIT-safe PCRE validator)
  - ✅ wss:// (TLS)
  - ✅ Auth — send Bearer/Basic + `Origin` header on upgrade
  - ✅ Unit + E2E test suites (live `WS_Server_CLI` driven by the client) + Demo project + docs (en-US + pt-BR)
  - ✅ Auto-reconnect with backoff (client-only)
  - ✅ Autobahn client conformance — 462 cases / 0 FAILED (sections 1-7, 12, 13)
  - ✅ Multi-client — instance-scoped callbacks + concurrent live clients on one shared loop (non-blocking `open()` + static `run()`)
- ✅ HTTP/2 (over the existing `HTTP_Server_CLI`)
  - ✅ HPACK header compression
  - ✅ Binary framing layer
  - ✅ Stream multiplexing (concurrent requests on one connection)
  - ✅ Server push (optional — feature-flag)
  - ✅ ALPN negotiation on the existing TLS-capable HTTPS server

---

## v0.18.0-beta ✅

> Focus: **Logging + Observability + Storage**

### ABI — Abstract Bootable Interface

- ✅ `Resources/Storage`: streaming storage facade — named disks + pluggable drivers mirroring `Resources/Cache`; stream-based contract (`write(path, $source)` / `read(path, $sink)`, constant memory); Local (atomic temp+rename, path jailing), Memory, and S3-compatible drivers (native SigV4 over a blocking socket: single PUT + parallel Multipart Upload); runtime folder `storage/` → `storage/` via the `BOOTGLY_STORAGE_DIR` constant
- ✅ `Resources/Storage`: rename `Storage->disk()` → `open()`
- ✅ `Resources/Storage`: realpath symlink jail (H1) + offline S3 SigV4 signer tests (M4)
- ✅ `Resources/Storage/S3`: fail-closed `read`/`list`/`clear` and reject CRLF in write options (header-injection) — security hardening

### ACI — Abstract Common Interface

- ✅ `Logs`: canonical log pipeline (Logger → Record → Processors → Handler) — File/Stream/Syslog/Pipe handlers, JSON + Line formatters, level/channel/tag/search filters, RFC5424 level enum, always-on rotation; real-time Monitor-mode log viewer (`CLI/UI/Components/Logs`)
- ✅ `Logs`: opt-in global sinks + per-module log files + JSON file default
- ✅ `Logs/Data/Display`: segment flags (MESSAGE/TIMESTAMP/CHANNEL/SEVERITY/CONTEXT) replacing the single mode constant
- ✅ `Observability`: native metrics stack — Counter/Gauge/Histogram instruments + registry + Snapshot DTO, Process/Runtime health collectors, cross-worker file-per-worker aggregation, JSON/Prometheus/OTLP exporters

### API — Application Programming Interface

- ✅ `Projects`: nested subprojects + unified security registry

### WPI — Web Programming Interface

- ✅ `HTTP_Server_CLI/Telemetry`: HTTP request telemetry instrument feeding `ACI/Observability`; demo `/health` + `/metrics` routes (Prometheus-default / JSON; OTLP push via `scripts/observability-ship.php`)
- ✅ `HTTP_Server_CLI/Request`: `store(key, path, $Disk)` streams a finished `multipart/form-data` upload from its temp file straight into a Storage disk (Local/S3), reclaiming the temp

### Bootgly

- ✅ CI: release-triggered Docker build+push of `bootgly/bootgly`
- ✅ `.gitignore`: update storage paths to storage

---

## v0.17.2-beta ✅

> Focus: **Response header cache + Plaintext/JSON resources & container foreground mode**

### WPI — Web Programming Interface

- ✅ `Response/Raw/Header`: per-response `type` Content-Type primitive + serialized header-block content cache — the default media type is set without populating header fields, so `build()` keeps its empty-fields fast path and the Raw wire-cache stays valid (no CRLF regex, no header-array churn)
- ✅ `Response/Resources/Plaintext`: new dedicated Plaintext resource (`$Response->Plaintext->send()`) — emits `text/plain` via `Header->type`
- ✅ `Response/Resources/JSON`: emit `application/json` through `Header->type` instead of a per-request header field
- ✅ Server: Foreground mode (`-f`) — run the HTTP server in the foreground (no daemonize) for containers
- ✅ Router: correct the Response method call in the `simple-benchmark` SAPI router

### Bootgly

- ✅ `Demo-TCP_Server_CLI`: repair boot + reference the Docker guide
- ✅ `.gitignore`: drop the trailing slash on the `.claude` entry

---

## v0.17.1-beta ✅

> Focus: **HTTP Server CLI security hardening**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI security audit — 12 findings (F-1…F-12) fixed:
  - ✅ `Request/Frame`: validate the request-line protocol token (`HTTP/1.0|1.1` only → `505`) and reject bare-`LF` line endings (`400`) — Host-allowlist / framing bypass closed (F-1)
  - ✅ `Interfaces/TCP_Server_CLI`: global + opt-in per-IP concurrent-connection ceiling (`Connections::check()`) — connection-exhaustion DoS (F-2)
  - ✅ `Router/Middlewares/RateLimit` + `TrustedProxy`: rate-limit on the immutable transport `Request::$peer`, not the proxy-mutable `address` (`trustForwarded` opt-in) (F-3)
  - ✅ `Router/Middlewares/RateLimit`: IPv6 `/64` aggregation, sliding-window default, global ceiling, pluggable key (F-4)
  - ✅ `Router/Middlewares/CSRF`: per-response masked token (`mask()`/`unmask()`) — closes the `Compression` BREACH oracle (F-5)
  - ✅ `Decoders/Decoder_Chunked`: absolute decode deadline (`expire()`); chunked size cap honors `requestMaxBodySize` (F-6)
  - ✅ `Response/Resources/JSONP`: serve as `text/javascript` + `X-Content-Type-Options: nosniff`; callback length capped (F-7)
  - ✅ `Router/Middlewares/CORS`: emit `Vary: Origin` on reflect; restrictive default allowlist (`[]`); never fall back to `*` (F-8)
  - ✅ `Request/Session`: cookie `Secure`/`HttpOnly` framework-owned (default `true`), no longer downgraded by `php.ini` (F-9)
  - ✅ `Decoders/Decoder_Downloading/Downloads`: reconcile the cross-worker SHM byte counter against on-disk bytes + sweep crash-orphaned temp files per worker (re)spawn (F-10)
  - ✅ `Router/Middlewares/ETag` + `Compression`: gate on `2xx`/`3xx` (skip error / auth bodies); `ETag` `If-None-Match` per RFC 7232 (`*` / list / weak comparison) (F-11)
  - ✅ `Response/Resources/View`: validate the view name locally (`[A-Za-z0-9_/-]`, reject `..` / leading `/` / null) before include — defense-in-depth at the sink (F-12)

---

## v0.17.0-beta

> Focus: **Caching + Queue + Events**

### ABI — Abstract Bootable Interface

- ✅ Cache abstraction (`ABI/Resources/Cache`)
  - ✅ File driver (via `ABI/IO/FS`)
  - ✅ APCu driver (per-process, single-worker only)
  - ✅ Shared-memory driver (per-host, cross-worker — System V `sysvshm` + `sysvsem`)
  - ✅ Redis driver (blocking — native RESP codec `ABI/Data/RESP`, optional `ext-redis` fast-path) — native RESP kept as zero-dependency canonical: benchmarked vs ext-redis 6.3.0, only +2–4% on the RTT-bound Cache workload (codec is 0.46 µs/cmd ≈ 0.5% of a 95 µs round-trip)
  - ✅ Async event-loop Redis driver (`ADI/Databases/KV/Drivers/Redis`, reuses `ABI/Data/RESP` on the async DBAL pool)
  - ✅ Shared backend for the multi-worker rate limiter (shared-memory or Redis — **not** APCu)
  - ✅ TTL, tags, invalidation
  - ✅ Cache-backed session handler (WPI `Session/Handlers/Cache` — new default; File handler opt-in)

### ACI — Abstract Common Interface

- ✅ Events system (`ABI/Events`)
  - ✅ `Emitter` — register listeners (`listen()`), fire events synchronously (`emit()`), propagation control (`Emission->stop()`); shared instance via `Emitter::$Instance`; async deferred (single-word method naming)
  - ✅ `Listener` interface + `Listeners` collection (`ABI/Events/Emitter/` — priority-ordered dispatch, single canonical contract)
  - ✅ `Event` marker interface (`ABI/Event` — event-identity enums, keyed by `spl_object_id`) + `Emission` carrier — immutable pay
  - ⭕️ WPI socket-loop constants (existing in `WPI\Events`, integer flags — **not** emitter-routed; consumed directly by the socket loop):
    - `EVENT_CONNECT` — client/server connection opened
    - `EVENT_READ` — package read from socket
    - `EVENT_WRITE` — package written to socket
    - `EVENT_EXCEPT` — socket exception path
  - ✅ Canonical domain events (emitter-routed; each is an enum case implementing `Event`, grouped per feature and wired in that feature — **not** strings, not in the core task — initial list, extend as needed):
    - ✅ `Request.received` — HTTP request fully decoded; `Request.handled` — request processed / response ready (`HTTP_Server_CLI\Request\Events`, both encoders)
    - ⭕️ `Response.sent` — **deferred**: a response is only truly flushed when Packages writes the `encode()` result, so this belongs at the transport layer (TCP `Packages`), not the encoder — left unwired (was prototyped in the encoder and removed for clarity)
    - `Auth.success` / `Auth.failure` — authentication guard outcome
    - `Gate.allow` / `Gate.deny` / `Policy.*` — authorization decision (v0.16 RBAC / Policies / Gates)
    - ✅ `Session.start` / `Session.regenerate` / `Session.destroy` (`…\Request\Session\Events`)
    - ✅ `Query.executed` / `DB.connected` / `Query.slow` (`ADI\Databases\SQL\Events`): `Executed` in `SQL\Operation::resolve()`; `Connected` (SQL-only) at the PostgreSQL driver auth-OK; `Slow` gated by `Operation::$slow` (0 = off, zero overhead — no `microtime()`)
    - ✅ `Transaction.begin` / `Transaction.commit` / `Transaction.rollback` (`…SQL\Transaction\Events`)
    - ✅ `Migration.up` / `Migration.down` (`…SQL\Schema\Migration\Events`; `Runner::apply()`)
    - ✅ `Cache.hit` / `Cache.miss` / `Cache.evict` (`ABI\Resources\Cache\Events`; `fetch()`/`delete()`)
    - ✅ `Worker.boot` / `Worker.shutdown` / `Worker.reload` (`ACI\Process\Events`; fork / `stop()` / SIGUSR2)
    - ✅ `Project.boot` / `Project.shutdown` (`API\Projects\Project\Events`; `Project::boot()` / `__destruct()`)

- ✅ Job Scheduler (`ACI/Schedule` — greenfield cron feature, **distinct from** the I/O `ACI/Events/Scheduler`)
  - ✅ Cron-style declarations via single verb `->repeat()` (`->repeat(Frequencies::Minutely)`, `->repeat(Frequencies::Daily, at: '03:00')`, `->repeat('*/5 * * * *')`)
  - ✅ `bootgly schedule run` / `bootgly schedule list` worker command (`ScheduleCommand`)
  - ✅ Overlap prevention via `->lock()` (file lock per job — `ACI\Schedule\Lock`)
  - ✅ Missed-run catch-up policy via `->recover()` (`Catchups::Skip` / `Catchups::Once`)
  - ✅ Lifecycle events `Started` / `Finished` / `Failed` / `Skipped` (`ACI\Schedule\Events`)
- ✅ Queue contract (`ACI/Queues` — layer-shared abstraction so CLI workers and WPI dispatch share one contract; avoids ACI → WPI back-dependency)
  - ✅ Job / Message contract + handler interface
  - ✅ Dispatcher + worker-loop contract (`Queues\Worker`, consumed by `queue run`)
  - ✅ Retry / failure / backoff policy (`Backoffs`: Fixed / Linear / Exponential; dead-letter)
  - ✅ File-based queue driver (default — atomic-rename claim under `storage/queues/`)
  - ✅ Redis queue driver, blocking — native RESP codec (`ABI/Data/RESP`) + optional `ext-redis` fast-path
    - 📋 Async event-loop Redis driver — **deferred** (resolution C: HTTP pushes only, blocking `reserve()` runs in the `queue run` worker; registerable later via `Drivers::register()` from ≥ADI)
  - ✅ Events
    - ✅ `Queue.dispatch` / `Queue.processed` / `Queue.failed`

### WPI — Web Programming Interface

- ✅ Queue dispatch adapter (`WPI/Queues/Messenger` — HTTP-facing adapter over the `ACI/Queues` contract)
  - ✅ HTTP-context job dispatching (enqueue from request handlers)
  - ✅ Worker processes (`bootgly queue run`)
  - ✅ Drivers, retry and failure policy inherited from the `ACI/Queues` contract

---

## v0.16.0-beta ✅

> Focus: **DBAL + ORM + Authorization**

### ADI — Abstract Data Interface

- ✅ Database abstraction layer (`ADI/Database`)
  - ✅ Paradigm split (`Database` / `Databases`)
    - ✅ `Database` is now the abstract transport core
    - ✅ `Databases` registry/factory resolves paradigm facades
    - ✅ SQL facade moved to `Databases\SQL`
    - ✅ PostgreSQL driver moved to `Databases\SQL\Drivers\PostgreSQL`
    - ✅ Generic `Connection` no longer carries PostgreSQL-only metadata
    - ✅ Generic `Operation` no longer carries SQL-only fields
    - ✅ Driver-level fake KV test proves non-SQL operation shapes can use the core lifecycle
  - ✅ Event-loop-native DB client — non-blocking I/O integrated with the existing `HTTP_Server_CLI` event loop so a DB call inside an active HTTP worker yields cooperatively instead of stalling the worker
    - ✅ PostgreSQL Protocol 3.0 native wire client (Startup, TLS, cleartext/MD5/SCRAM auth, Simple Query, Extended Query)
    - ✅ Awaitable `Operation` + `Readiness` deadline integration for `Response::wait()`
    - ✅ Recoverable error handling, timeout propagation and PostgreSQL CancelRequest side-channel
    - ✅ PostgreSQL metadata messages (`BackendKeyData`, `ParameterStatus`, `NoticeResponse`, `NotificationResponse`)
    - ✅ PostgreSQL result type conversion with NUMERIC precision preserved as string
  - ✅ Connection pooling for async server (pool reused across in-flight requests on the same worker; back-pressure when pool is exhausted)
    - ✅ Per-worker reusable connection cache
    - ✅ Pending queue with operation deadlines
    - ✅ Ordered MVP pipelining with per-operation release/drain
  - ✅ Prepared statement cache (per-connection LRU)
    - ✅ Statement-level Describe and cached server-confirmed parameter OIDs
    - ✅ Binary Bind format selection only after server ParameterDescription
  - ✅ Result convenience surface
    - ✅ First row view (`Result->row`)
    - ✅ First cell view (`Result->cell`)
    - ✅ Empty-result/count views (`Result->empty`, `Result->count`)
  - ✅ Transactions (begin / commit / rollback)
  - ✅ Savepoints (nested transactions)
  - ✅ Query Builder (fluent API)
  - ✅ Schema Builder (migrations)
    - ✅ `bootgly project <project> migrate` CLI subcommand
    - ✅ Up / down migration runners, status table, lock file
    - ✅ Migration sync against the current database schema snapshot
  - ✅ Seeders (reuse `ACI/Faker` base — no duplicate faker stack)
  - ✅ Read replicas / write-read splitting
- ✅ ORM (Data Mapper)
  - ✅ Model definition
  - ✅ Scopes and query hooks
  - ✅ Relationships (hasOne, hasMany, belongsTo, belongsToMany)
  - ✅ Explicit / deferred batch relation loading (single-level, batched per relation, no N+1)
  - ✅ Eager loading (auto-await + auto-attach)
  - ✅ Lazy loading (lazy collection/reference, batched per hydration window)

### API — Application Programming Interface

- ✅ Native Configs integration for `ADI/Database`
  - ✅ `DatabaseConfig` adapter for `database` scope materialization
  - ✅ ADI-safe layering: API adapter depends on ADI; ADI does not depend on API Configs
  - ✅ TLS/default fallback validation and multi-driver selection contract

### WPI — Web Programming Interface

- ✅ Async database demo route for `HTTP_Server_CLI`
  - ✅ Scheduled response route waits on DB `Readiness` without blocking the worker
  - ✅ Demo `database` config scope uses ADI defaults
- ✅ Database developer experience for `HTTP_Server_CLI`
  - ✅ WPI `Runner` helper to hide the low-level `advance()`/`Readiness` loop from app handlers
  - ✅ Demo route: connection ping (`SELECT 1`)
  - ✅ Demo route: parameterized select
  - ✅ Demo route: scalar type conversion
  - ✅ Demo route: setup/seed table (`bootgly_demo_users`)
  - ✅ Demo route: users list from demo table
  - ✅ Demo route: parameterized user lookup from demo table
  - ✅ Demo route: recoverable error handling
  - ✅ Demo route: pool/concurrent queries
  - ✅ Demo route: slow query non-blocking check
  - ✅ Demo route: Configs-driven connection
  - ✅ Benchmark scenarios: native low-level async vs Response resource async
  - ✅ Benchmark competitors: Database Swoole vs Bootgly DBAL
- ✅ Authorization
  - ✅ RBAC (Role-Based Access Control)
  - ✅ Policies
  - ✅ Gates

#### Verifications

- [x] `AI_AGENT=1 bootgly test 12` — ADI/Database suite (30 cases)
- [x] `AI_AGENT=1 bootgly test 16` — ORM repository suite (explicit batch loading with optional real-I/O skips)
- [x] `BOOTGLY_ORM_ASYNC_E2E=1 AI_AGENT=1 bootgly test 16` — ORM PostgreSQL real-I/O suite (CRUD + deferred/eager/lazy relation loading) — **required pre-commit gate; ORM 👍→✅ promotion needs this run green, not the stub-only run**
- [x] `AI_AGENT=1 bootgly test 14` — API Configs suite (14 cases)
- [x] `AI_AGENT=1 bootgly test 23 180` — HTTP scheduled readiness E2E (180 assertions)
- [x] Focused PHPStan for ADI/Database + Configs adapter
- [x] Database Resource Benchmark — native async vs Response resource async baseline collected with TCP_Client
- [x] Phase 8 paradigm split — `Database` / `Databases\SQL` refactor with fake KV smoke test
- [x] Schema Builder + migrations suite
- [x] `git diff --check`

---

## v0.15.0-beta ✅

> Focus: **Testing improvements + Configuration + 2 new middlewares(Authentication + Input Validation)**

### ABI — Abstract Bootable Interface

- ✅ Differ engine (`ABI/Differ`) for test diagnostics and coverage diffs
  - ✅ Diff model (`Diff`, `Chunk`, `Line`) with iterable value objects
  - ✅ LCS calculators optimized for memory and time strategies
  - ✅ Output renderers: changed-lines only, unified, strict unified, ANSI escaped
  - ✅ Unified diff parser
  - ✅ Self-tests for model, calculators, renderers, parser, and configuration errors

### ACI — Abstract Common Interface

- ✅ Tests: Fixtures (`ACI/Tests/Fixture`)
  - ✅ Lifecycle state machine (`Pristine`, `Preparing`, `Ready`, `Disposing`, `Disposed`)
  - ✅ Idempotent `prepare()` / `dispose()` hooks
  - ✅ Deterministic state bag with `fetch()`, `update()`, `reset()`, and `clear()`
  - ✅ `Fixturable` integration in test specifications
  - ✅ HTTP Server test fixtures (`WPI/Nodes/HTTP_Server_CLI/Tests/Fixtures`)
- ✅ Tests: Mocks / Fakers / Spies
  - ✅ Typesafe `Mock` proxy generation for interfaces and non-final classes
  - ✅ Stubbed return values and configured throwable paths
  - ✅ Call recording with method, arguments, return value, throwable, and timestamp
  - ✅ `verify()` call-count assertions and `reset()` cleanup
  - ✅ `Spy` wrapper for real instances with delegation and call tracking
  - ✅ Deterministic `Faker` base and `Fakers` trait dispatch
  - ✅ Built-in fakers: Email, Integer, Name, Text, UUID
- ✅ Tests: Code coverage integration
  - ✅ `Coverage` session API: `start()`, `stop()`, `report()`
  - ✅ Driver abstraction with XDebug, PCOV, Native, and Nothing drivers
  - ✅ XDebug coverage-mode guard and PCOV fallback detection
  - ✅ Native coverage analyzer/compiler/universe with strict and parity modes
  - ✅ Coverage hit collection, reset, executable-line seeding, and canonical path merge
  - ✅ Include scopes and exact SUT target filtering
  - ✅ Text, Clover XML, and single-page HTML reports
  - ✅ Optional text report per-file diff output via `ABI/Differ`
  - ✅ `bootgly test` coverage flags: driver, report, native mode, and diff
- ✅ Tests: Fakes (`ACI/Tests/Doubles/Fake`)
  - **Need**: stateful in-memory working impls of collaborators (Session, Cache, Repository, Clock) for unit tests where `Mock`'s per-method canned-return contract does not fit. `Mock` matches arguments and returns fixed values; it cannot natively express coupling like `set('k','v')` → `check('k')` → `get('k')` across multiple calls without forcing tests to bind shared state through closures and `ArrayObject` references — same code volume as inline anonymous classes plus `eval`/Reflection overhead per Proxy build. `Fake` fills this exact gap: a working substitute keyed by behavior, not by canned returns.
  - ✅ `Fake.php` — abstract base class implementing the existing `Bootgly\ACI\Tests\Doubles\Doubling` interface (`reset(): static`); registers in the existing `Doubles` collection alongside `Mock` and `Spy`. No new collection wiring required.
  - ✅ `Fake/Memory.php` — in-memory key-value substitute matching the `Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session` access shape: `check(string $name): bool`, `get(string $name, mixed $default = null): mixed`, `set(string $name, mixed $value): void`, `delete(string $name): void`, `list(): array<string,mixed>`, `flush(): void`, `reset(): static`. Drop-in for any KV-shaped collaborator, not coupled to `Session::class`.
  - ✅ `Fake/Clock.php` — deterministic time substitute with `now: float`, `advance(int|float $seconds): void`, `freeze(int|float $at): void`, `reset(): static`. Removes `time()`/`microtime()` flakiness from rate-limiter and TTL tests.
  - ✅ Self-tests in the existing ACI suite (`Bootgly/ACI/Tests/tests/5.3.x-Fake-*.test.php`) — round-trip `set`/`get`/`check`/`delete`/`list`/`flush`/`reset` for `Memory`; deterministic `advance`/`freeze`/`reset` for `Clock`. Reuses the existing `Specification` + `Assertions` (Level 3) test format already used across `Bootgly/ACI/Tests/Doubles/`.
  - ✅ Refactor `Bootgly/WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/tests/9.1-csrf.test.php` — replace the inline anonymous-class Session double inside `$createSession` with `new Fake\Memory()`. Token-persistence, set-then-get, and `check()` calls all flow without bespoke mock code.
  - ✅ Refactor `Bootgly/WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/tests/2.1-rate_limit.test.php` — replace the random-IP static-counter pollution workaround (`$ip = 'test-' . bin2hex(random_bytes(4))`) with `Fake\Clock`; `RateLimit` accepts a `null|Closure` clock provider and exposes `reset()` for deterministic static-counter cleanup.
  - ✅ No third-party dependency. Same layering rule as `Mock`/`Spy`: `Fake` lives in ACI; WPI tests reference it across the allowed direction.

### API — Application Programming Interface

- ✅ Configuration system — base infrastructure (`API/Environment/Configs`)
  - ✅ `Configs.php` — base loader/facade for framework `configs/` scopes
  - ✅ `Configs/Config.php` — mutable config tree node with object navigation, `bind()`, `get()`, `up()`, `down()`, required bindings and deep `merge()`
  - ✅ `Configs/Config/Types.php` — strict scalar casts for `Integer`, `Float`, `Boolean`, and `String`
  - ✅ `Configs/Scopes.php` — registry/collection of loaded scopes
  - ✅ Directory-per-scope structure — each config category is a folder (e.g. `configs/database/`, `configs/server/`)
  - ✅ Scoped `.env` files per category (not monolithic):
    - `configs/<scope>/.env` — shared across all environments
    - `configs/<scope>/.env.development` — development-only overrides
    - `configs/<scope>/.env.production` — production-only overrides
  - ✅ PHP config file per scope: `configs/<scope>/<scope>.config.php` — structure + defaults referencing env vars
  - ✅ Environment-aware resolution: `.env` → `.env.<environment>` → `.config.php` (later env files override earlier env values before PHP config binds)
  - ✅ Config access via scope lookup + object navigation (`$Configs->get('database')->Default->get()`)
  - ⭕️ Dot-notation (`$Environment->Configs->get('database.default')`) rejected; `Configs::get()` is scope-only to avoid public-property collisions and keep PHPStan checks precise
  - ✅ Lazy loading — config scope loaded on first access, not at boot
  - ✅ `.env` values stay local to the loader instance; no `putenv()` leakage between scopes/projects
  - ✅ Fail-closed `.env` policy: uppercase variable validation, per-scope `allow()` allowlists, and `lock()` runtime-only keys
  - ✅ Path traversal hardening with scope/environment validation and `File::guard()` before reading `.env` or requiring `.config.php`
  - ✅ Required config values use `bind(required: true)` as the single canonical path
  - ✅ Trust boundary documented for executable `<scope>.config.php` files
  - ✅ `.env` files gitignored by default — secrets never versionable; `*.config.php` files always versionable
  - ✅ PHPStan integration for dynamic config properties and unbound `Config::get()` checks
- ✅ Configuration system — project-level extension (`API/Projects/Configs`)
  - ✅ `Configs.php` extends `Environment\Configs` — overrides base path to project `configs/`
  - ✅ Same scoped `.env` + `.config.php` structure per project directory
  - ✅ `Projects\Configs::overlay()` deep-merges project scopes over framework scopes; project values win
  - ✅ Overlay keeps framework/project `.env` values local and does not mutate process environment
  - ✅ `Project` gains nullable `->Configs` property; `Project->boot()` initializes it when the project has a `configs/` directory
  - 📋 Define lazy auto-overlay behavior for `Project->Configs` over `Environment::$Configs`

### WPI — Web Programming Interface

- ✅ Input Validation layer
  - ✅ Rule-based validators (`Required`, `Minimum`, `Maximum`, `Email`, `Regex`, `Integer`, `Size`, `MIME`, `Extension`)
  - ✅ Request validation integration via `Validator` middleware over `Request/Validation` pipeline
  - ✅ Custom validation rules (extend `Request\Validators`)
- ✅ Authentication system
  - ✅ HTTP Basic auth compatibility
  - ✅ Token-based auth (Bearer)
  - ✅ Session-based guards (file driver only at v0.15; pluggable session drivers — DB/Redis-like — move with v0.16 ADI Database and v0.17 ABI Cache)
  - JWT
    - ✅ JWT integration — HS256, typed verification, `Key`/`KeySet`, `kid`, verified headers, RS256, and local RSA JWKS parsing
    - ✅ JWT claim policies (`iss`, `aud`, required `sub`, required `jti`) and deterministic clock controls
    - ✅ JWT remote JWKS fetch with process-local cache and refresh on `kid` miss
    - ✅ JWT remote JWKS persistent cache/store integration via `Session`
    - ✅ JWT refresh token rotation, family revocation, and `jti` replay protection with persistent cache/store
    - ⭕️ JWT additional algorithms (`HS384`, `HS512`, `RS384`, `RS512`, ECDSA, EdDSA)
  - ⭕️ Digest HTTP auth (`WPI/Modules/HTTP/Server/Response/Authentication/Digest`)

### Bootgly

#### Verifications

- [x] ABI Differ self-tests pass for model, calculators, renderers, and parser
- [x] Mocks can assert method calls and parameters
- [x] Mocks record returned values and thrown exceptions
- [x] Spies can wrap real instances, delegate calls, and preserve recorded arguments/returns
- [x] Fakers generate deterministic values from seeds
- [x] Code coverage reports generated correctly in text, Clover, and HTML formats
- [x] Coverage driver detection supports XDebug, PCOV, Native, and Nothing backends
- [x] Native coverage can instrument executable lines and collect hits without third-party packages
- [x] Coverage reports can be scoped to selected suites and exact SUT files
- [x] Coverage text reports can render covered/uncovered line diffs
- [x] Fixtures can set up and tear down test state
- [x] Fixtures can reset deterministic state between cases
- [ ] API Server tests can use fixtures for request/response setup
- [x] WPI HTTP Server tests can use fixtures for request/response probe state
- [] API Server tests can use mocks for middleware and handlers
- [x] WPI middleware tests can use mocks for Request/Response
- [x] Authentication methods correctly authenticate valid credentials and reject invalid ones
- [x] `API/Environment/Configs` loads framework scopes from `configs/<scope>/` directories
- [x] Scoped `.env` loads base values, `.env.<environment>` overrides per environment
- [x] `<scope>.config.php` resolves env/local-env/default values through `Config::bind()`
- [x] Config values accessible via scope lookup + object navigation (`$Configs->get('database')->Default->get()`)
- [x] Dot-notation config access rejected; `Configs::get()` is scope-only
- [x] Config scopes lazy-loaded on first access
- [x] `.env` files excluded from version control; `*.config.php` files safe to commit
- [x] `.env` values remain isolated from the process environment and project overlays
- [x] Required config bindings fail closed and strict casts reject ambiguous scalar values
- [x] Config scope/environment names are path-safe and guarded against traversal
- [x] Config `.env` policy supports uppercase validation, allowlists and locked runtime-only keys
- [x] `API/Projects/Configs` extends `Environment/Configs` with project base path
- [x] `Projects\Configs::overlay()` deep-merges project scopes over framework defaults
- [x] `Project->boot()` initializes `Project->Configs` when the project has a `configs/` directory
- [x] `Project->Configs` provides project-scoped config access
- [ ] Lazy auto-overlay for `Project->Configs` over `Environment::$Configs` is defined and implemented
- [x] Config-specific PHPStan dynamic properties/unbound-access rules registered
- [x] Static analysis — PHPStan level 9
- [x] Code style — Bootgly conventions / rules

---

## v0.14.12-beta ✅

> Focus: **Property-based fuzz testing infrastructure for HTTP_Server_CLI and RFC-compliant header parsing fix**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Tests/Fuzz` — property-based fuzz testing infrastructure (`Grammar`, `Grammar/Body`, `Grammar/Headers`, `Property`, `Sockets`); 5 fuzz scenarios covering header casing/ordering invariants, pipelined CL+chunked mix, slow body trickling, multipart shape fuzz, and degenerate framing
- ✅ HTTP Server CLI: `Request/Frame` + `Request/Raw/Header` — RFC compliance fix: RFC 9110–valid header values that contain no folding whitespace were incorrectly rejected; acceptance logic corrected and regression test `04.04-rfc_valid_no_space_headers` added

---

## v0.14.11-beta ✅

> Focus: **Server infrastructure hardening — centralized HTTP/1.1 framing, decoder state machine, async write backpressure, aggregate upload disk cap, and POST globals elimination**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Request\Frame` — new centralized HTTP/1.1 framing parser; `Content-Length`, `Transfer-Encoding`, `Expect`, and multipart `Content-Type` are now matched with `(?:^|\r\n)` anchors covering first-header position, closing the Critical Finding 1 first-header framing blind spot at the architectural level
- ✅ HTTP Server CLI: `Decoders` — decode methods now return a `States` enum (`INCOMPLETE`, `COMPLETE`, `REJECTED`) instead of overloaded integer byte counts, eliminating ambiguity between "not ready" and "zero bytes decoded" that previously enabled premature handler dispatch
- ✅ TCP Server CLI: `Packages` — full backpressure-aware async write state machine; partial writes are stored with byte offsets and the socket is registered for write-readiness events, replacing the immediate-close-on-zero strategy with a proper non-blocking write pipeline
- ✅ HTTP Server CLI: `Decoder_Downloading` — aggregate disk cap across all in-flight multipart uploads per worker (`maxDownloadsDiskCap`); enforced before writing each chunk to temp storage, preventing disk exhaustion via concurrent upload flooding
- ✅ HTTP Server CLI: `Request::$fields` replaces `$_POST` / `$_FILES` globals; POST form data and uploaded file metadata are now stored in per-request instance state, eliminating cross-request data leakage through PHP superglobals in long-running worker processes (+3% throughput)

---

## v0.14.10-beta ✅

> Focus: **Response header name validation against RFC 9110 token syntax**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Response\Raw\Header` — added `isValidName()` private validator (RFC 9110 §5.1 token regex `/^[!#$%&'*+.^_\`|~0-9A-Za-z-]+$/D`); `set()` strips CRLF from field name, validates, and returns `false` on failure; `append()` validates and silently skips on failure; `queue()` validates and returns `false` on failure; `prepare()` filters the array dropping invalid names and CRLF-stripping values before `build()`
- ✅ HTTP Server CLI: Security regression test `22.01-response_header_name_validation` — drives `set()`, `queue()`, and `prepare()` with CRLF-injected names and values; asserts the built `Header->raw` contains no synthesized header line

---

## v0.14.9-beta ✅

> Focus: **Session strict mode — rotate client-supplied unknown session IDs before first write**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Request->Session` getter — cookie IDs failing the canonical `^[a-f0-9]{32,64}$` format are replaced with a fresh ID before `Session` construction; format-valid IDs that do not load existing data are rotated via `Session::rotate()` before any first write, preventing an attacker-chosen ID from ever being persisted
- ✅ HTTP Server CLI: `Session` — added `$loaded` flag (true only when `Handler::read()` returns existing data) and `rotate(string $newId)` method that replaces the ID in-place without touching storage or emitting `Set-Cookie`
- ✅ HTTP Server CLI: Security regression test `21.01-session_strict_mode_unknown_id` — sends a format-valid but server-unknown `PHPSID` cookie and asserts the handler's mutated session uses a fresh server-generated ID

---

## v0.14.8-beta ✅

> Focus: **Request header field names normalized to lowercase for full case-insensitivity compliance**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Request\Raw\Header::build()` — header field names now lowercased at parse time (RFC 9110 §5.1), making all lookups via `Header::get()` and `Header::append()` operate on a single canonical form; eliminates middleware bypass vectors for `AUTHORIZATION`, `ORIGIN`, `X-FORWARDED-FOR`, `COOKIE`, and any other attacker-controlled mixed-case header names
- ✅ HTTP Server CLI: `Request\Raw\Header\Cookies::build()` — updated to look up the canonical lowercase `cookie` key
- ✅ HTTP Server CLI: `Header::get()` simplified to a single lowercase lookup (removed redundant per-call dual lookup)
- ✅ HTTP Server CLI: Security regression test `20.01-header_case_insensitivity` covering uppercase `AUTHORIZATION`, `ORIGIN`, `X-FORWARDED-FOR`, and `COOKIE` resolution

---

## v0.14.7-beta ✅

> Focus: **Multipart text field memory caps and TCP nonblocking write backpressure implementation**

### WPI — Web Programming Interface

- ✅ TCP Server CLI: `Packages` — backpressure implementation for zero-byte nonblocking `fwrite()` returns: stops streaming and closes the slow client immediately instead of busy-spinning (completes the fix whose regression test shipped in v0.14.6)
- ✅ HTTP Server CLI: `Decoder_Downloading` — independent memory caps for multipart text fields (`maxMultipartFieldSize` 1 MiB), part headers (`maxMultipartHeaderSize` 8 KiB), field count (`maxMultipartFields`), and file count (`maxMultipartFiles`); oversized text fields, headers, and excess parts are now rejected with `413` before buffering; server configuration exposes these limits as optional arguments
- ✅ HTTP Server CLI: Security regression test `19.01-multipart_text_field_memory_cap` covering 1 MiB+1 field rejection scenario

---

## v0.14.6-beta ✅

> Focus: **Nonblocking write backpressure spin prevention in TCP Server**

### WPI — Web Programming Interface

- ✅ TCP Server CLI: `Packages` — hardened nonblocking `fwrite()` loop to correctly handle zero-byte write returns (kernel send-buffer full), preventing a busy-spin that could consume 100% CPU when a slow client stalls the connection
- ✅ HTTP Server CLI: Security regression test `18.01-nonblocking_write_backpressure_spin` covering zero-byte write backpressure scenario

---

## v0.14.5-beta ✅

> Focus: **Prevent handler execution before HTTP request body is fully received**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `Encoder_` — production encoder now defers handler dispatch until the request body is fully received, preventing partial-body handler execution that could expose incomplete data to application logic
- ✅ HTTP Server CLI: Security regression test `17.01-handler_before_body_completion` covering premature handler dispatch scenario

---

## v0.14.4-beta ✅

> Focus: **BodyParser cross-route limit leak and Content-Length smuggling prevention**

### WPI — Web Programming Interface

- ✅ HTTP Server CLI: `BodyParser` — middleware no longer mutates the global `Request::$maxBodySize` static; limit is now applied per-request at decode time via a temporary override, preventing a low-limit route from silently capping uploads on all subsequent routes
- ✅ HTTP Server CLI: `Request` — hardened against HTTP request smuggling via `Content-Length` placed as first header; security regression tests `12.01-bodyparser_limit_bypass_decode_time`, `16.01-bodyparser_global_maxbodysize_cross_route_leak`, and `04.03-content_length_first_header_smuggling` added

---

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
<?php

use Bootgly\ACI\Tests\Suites;

return new Suites(
   directories: [
      // ! Abstract Bootable Interface (ABI)
      // ? 1
      #'Bootgly/ABI/Configs/*', // ! Not testable (for now)
      // ? 2
      'Bootgly/ABI/Data/__Array/',
      'Bootgly/ABI/Data/__String/',
      'Bootgly/ABI/Data/__String/Bytes/',
      #'Bootgly/ABI/Data/__String/Escapeable/', // ! Not testable directly (traits)
      'Bootgly/ABI/Data/__String/Path/',
      // ? 3
      #'Bootgly/ABI/Debugging/*', // ! Not testable
      // ? 3.5
      'Bootgly/ABI/Differ/',
      // ? 4
      'Bootgly/ABI/IO/FS/Dir/',
      'Bootgly/ABI/IO/FS/File/',
      'Bootgly/ABI/IO/IPC/Pipe/', // ! Testable only individually
      // ? 5
      #'Bootgly/ABI/Resources/*', // ! Registered at the end to keep suite indices stable
      // ? 6
      #'Bootgly/ABI/Templates/Directives/', // ! Not testable directly (part of Template)
      #'Bootgly/ABI/Templates/Iterator/', // ! Not testable (for now)
      #'Bootgly/ABI/Templates/Iterators/', // ! Not testable (for now)
      'Bootgly/ABI/Templates/Template/',
      'Bootgly/ABI/Templates/Template/Escaped/',

      // ! Abstract Common Interface (ACI)
      'Bootgly/ACI/Tests/',

      // ! Abstract Data Interface (ADI)
      'Bootgly/ADI/Database/',
      'Bootgly/ADI/Databases/SQL/Builder/',
      'Bootgly/ADI/Databases/SQL/Schema/',
      'Bootgly/ADI/Databases/SQL/Seed/',
      'Bootgly/ADI/Databases/SQL/Repository/',
      'Bootgly/ADI/Table/',
      'Bootgly/ADI/Validation/',
      'Bootgly/ADI/Validators/',

      // ! Application Programming Interface (API)
      'Bootgly/API/Environment/Configs/',
      'Bootgly/API/Security/',
      'Bootgly/API/Workables/Server/',

      // ! Command Line Interface (CLI)
      'Bootgly/CLI/Commands/',

      // ! Web Programming Interface (WPI)
      'Bootgly/WPI/Connections/tests/',
      // # HTTP_Client_CLI
      // Atomic
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/Atomic/',
      // E2E (use Bootgly's TCP_Server_CLI)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E/',
      // E2E SSL (use Bootgly's TCP_Server_CLI with SSL)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/',
      // # HTTP_Server_CLI
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/',
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Response/Resources/',
      // E2E (use Bootgly's TCP_Client_CLI)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/E2E/',
      // Security
      #'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Security/',
      // Fuzz (property-based / structure-aware fuzzing)
      #'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Fuzz/',

      // ! Appended last so earlier suite indices stay stable (coverage probes
      // ! in Bootgly/ACI/Tests/tests hardcode indices 4, 8 and 14-21).
      'Bootgly/ABI/Data/RESP/',
      'Bootgly/ABI/Resources/Cache/',
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Request/Session/',
      'Bootgly/ABI/Events/',
      'Bootgly/ACI/Process/',
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Request/',
      'Bootgly/ADI/Databases/SQL/',
      'Bootgly/API/Projects/Project/',
      'Bootgly/ACI/Schedule/',
      'Bootgly/ACI/Queues/',
      'Bootgly/WPI/Queues/',
      'Bootgly/ACI/Logs/',
      'Bootgly/ACI/Observability/',
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Telemetry/',
      'Bootgly/ABI/Resources/Storage/',
      'Bootgly/API/Projects/',
      // # WS_Server_CLI (WebSocket) — handshake + frame-codec unit suites
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/',
      // E2E (live test-mode server driven by a raw WebSocket client)
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/E2E/',
      // E2E over TLS (wss://)
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/E2E_TLS/',
      // E2E handshake auth (Bearer + Basic guards, challenge, identity/claims)
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/E2E_Auth/',
      // E2E streaming (incremental UTF-8 fail-fast + outbound fragmentation)
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/E2E_Streaming/',
      // E2E handshake predicate (HandshakeRequested — Origin allowlist -> 403)
      'Bootgly/WPI/Nodes/WS_Server_CLI/tests/E2E_Handshake/',
      // # WS_Client_CLI (WebSocket client) — handshake + frame-codec unit suites
      'Bootgly/WPI/Nodes/WS_Client_CLI/tests/',
      // E2E (live test-mode WS_Server_CLI driven by the WS_Client_CLI)
      'Bootgly/WPI/Nodes/WS_Client_CLI/tests/E2E/',
      // E2E over TLS (wss://)
      'Bootgly/WPI/Nodes/WS_Client_CLI/tests/E2E_TLS/',
      // E2E adversarial (raw server sends malformed frames -> client rejects)
      'Bootgly/WPI/Nodes/WS_Client_CLI/tests/E2E_Adversarial/',
      // Security (re-enabled — appended LAST to keep coverage-probe indices
      // 4, 8 and 14-21 stable; the original in-place slot above stays
      // commented for documentation).
      #'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Security/',
      // # HTTP/2 (RFC 9113) — protocol primitive unit suites
      'Bootgly/WPI/Modules/HTTP2/tests/',
      // E2E h2c prior-knowledge (live test-mode server driven by a raw HTTP/2 client)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/HTTP2/',
      // E2E h2 over TLS-ALPN (negotiation, fallback, curl interop)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/HTTP2_TLS/',
      // # CLI Terminal — size resolution + cursor position contract + Progress anchoring
      'Bootgly/CLI/Terminal/',
      // # Benchmark harness — case options schema + sweep expansion
      'Bootgly/ACI/Tests/Benchmark/',
      // # CLI UI Components — Question (validated input + yes/no confirm)
      'Bootgly/CLI/UI/Components/',
      // # CLI UX — Form (sequential multi-field input) + Prompt (bottom-fixed REPL)
      'Bootgly/CLI/UX/Components/',
      // # ACI/Mail — SMTP client unit suites (config, protocol codec, auth strings)
      'Bootgly/ACI/Mail/',
      // E2E (forked scripted mock SMTP server on 9994-9998; TLS certs generated at boot)
      'Bootgly/ACI/Mail/tests/E2E/',
      // # WPI/Services/Mail — web mail service (queued dispatch via the Courier handler)
      'Bootgly/WPI/Services/Mail/',
      // # ABI/Data/__String/Theme — theme system (public API, builtins, Escaped seam)
      'Bootgly/ABI/Data/__String/Theme/',
      // # ABI/Debugging — throwable rendering (CLI/HTML targets), reporter seam, debug page
      'Bootgly/ABI/Debugging/',
      // # ABI/Data/Language — i18n minimal contract (translate, catalogs, locale negotiation)
      'Bootgly/ABI/Data/Language/',
      // # WPI/Modules/HTTP/Server — SSE `text/event-stream` wire encoder
      'Bootgly/WPI/Modules/HTTP/Server/tests/',
      // # HTTP_Server_CLI unit doubles — transport-failure paths no live
      //   server spec can reach (appended last: indices stay stable)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Unit/',
      // # HTTP_Server_CLI/ACME_Client — Auto-TLS protocol primitives
      //   (config validation, JWK/JWS/CSR/nonces — no network)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/ACME_Client/tests/',
      // E2E built-in HTTP-01 responder (port 8098; hook wins over user handler)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/ACME_Challenge/',
      // E2E Auto-TLS bootstrap + port helper + hot cert swap (ports 8099/8078, Docker-free)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/ACME_Swap/',
      // E2E full ACME issuance against Pebble (opt-in: BOOTGLY_ACME_E2E=1 + Pebble on :14000)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/ACME_E2E/',
      // # TCP_Client_CLI — bounded async dial + connection Pool unit suites
      'Bootgly/WPI/Interfaces/TCP_Client_CLI/tests/',
      // # HTTP_Client_CLI HTTP/2 — Session engine unit suites (frame-fed, socketless)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/HTTP2/',
      // E2E h2c prior-knowledge (client vs live test-mode HTTP_Server_CLI on 8087)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/HTTP2_E2E/',
      // E2E h2 over TLS-ALPN (negotiation, fallback, multiplex on 8088)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/HTTP2_TLS/',
      // # UDP_Server_CLI — per-peer Connection lifecycle unit doubles
      'Bootgly/WPI/Interfaces/UDP_Server_CLI/tests/',
      // # CLI UI Atoms — Text (+Effects) typographic primitive
      'Bootgly/CLI/UI/Atoms/',
      // # CLI UI Base — Frame (region canvas: buffer, render, diff, drain)
      'Bootgly/CLI/UI/Base/',
   ]
);

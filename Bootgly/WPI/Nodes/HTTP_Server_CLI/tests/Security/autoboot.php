<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\Security;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function sleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      if ( !defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      sleep(3); // @ Ensure the previous suite's worker processes have terminated and released state locks.

      HTTP_Server_CLI::pretest($Suite, 'Security');

      // ! Host-allowlist PoCs configure this worker-global static before the
      //   fork. Their client-side cleanup cannot mutate the already-forked
      //   worker, so declare every legitimate suite authority here. The
      //   attacker-controlled hosts in those PoCs remain absent and rejected.
      Request::$allowedHosts = [
         'localhost',
         'control.example.test',
         'tenant-a.example.test',
         'tenant-b.example.test',
         'vary.example.test',
      ];

      // @ Ensure Middlewares pipeline is initialized before any request reaches
      //   Encoder_ — PoCs open side connections that may race the @test init
      //   signal to the worker.
      if ( ! isset(SAPI::$Middlewares)) {
         SAPI::$Middlewares = new Middlewares;
      }

      // ! Single worker is intentional — these PoCs exercise per-worker state bleed.
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8081,
         workers: 1
      );

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();
      Request::$allowedHosts = [];

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      // @ Order-independence: the harness injects an `X-Bootgly-Test: N`
      //   header and the Server installs the handler at that exact slot
      //   (`Server::boot($testIndex)`), so the ordering below is purely
      //   for human readability — inserting / reordering tests no longer
      //   affects dispatch.
      // # Request input()
      // Content-Type confusion — parse_str() fallthrough on invalid JSON
      '01.01-input_content_type_confusion_parse_str_fallthrough',
      // # Decoder
      // cross-connection state bleed
      '02.01-decoder_chunked_cross_connection_state',
      '02.02-decoder_downloading_cross_connection_state',
      '02.03-decoder_waiting_stale_timestamp',
      // L1 cache cross-connection Request reuse
      '03.01-decoder_cache_shared_request_across_connections',
      // one-shot query-key churn (DoS)
      '03.02-decoder_cache_one_shot_key_eviction_dos',
      // # Request
      // Content-Length validation
      '04.01-content_length_negative_accepted',
      '04.02-content_length_strict_parse_bypass',
      '04.03-content_length_first_header_smuggling',
      '04.04-rfc_valid_no_space_headers',
      // # Request Session
      // file handler unserialise without integrity
      '05.01-session_file_unserialize_forgery',
      // # Decoder Chunked
      // blind 2-byte CRLF consumption → TE smuggling
      '06.01-chunked_decoder_blind_crlf_consumption',
      // # Decoder Downloading (multipart)
      // boundary not validated (charset + length cap)
      '07.01-multipart_boundary_injection_and_oversize',
      // # Decoder_ cache
      // shallow clone → sub-object state bleed
      '08.01-decoder_cache_shallow_clone_subobject_bleed',
      // # Request Expect
      '09.01-expect_100_continue_with_te_chunked',
      '09.02-expect_100_continue_with_oversized_content_length',
      // # Response
      '10.01-response_path_traversal_sibling_prefix_bypass',
      '10.02-response_send_file_require_bypasses_view_jail',
      '10.03-response_redirect_open_redirect_backslash_bypass',
      '10.04-response_upload_file_object_bypasses_project_jail',
      // # Request Host
      '11.01-host_header_allowlist_spoofing',
      // # BodyParser Middleware
      '12.01-bodyparser_limit_bypass_decode_time',
      // # Request Session
      // unconditional Set-Cookie on read-only access (fixation + DoS)
      '13.01-session_unconditional_set_cookie_on_read',
      // # Response render()
      // user-controlled data overwrites Template closure sentinel via extract()
      '14.01-response_render_extract_file_inclusion',
      // audit F-12 — View::render() must validate the view name locally
      // (reject ../null-byte/absolute/non-[\w/-]) before include, not rely
      // solely on the downstream File::guard() default.
      '14.02-view_render_local_input_validation',
      // # Router
      // unbounded promotion of catch-all URLs into staticCache (memory DoS)
      '15.01-router_catchall_negative_cache_pollution',
      // # BodyParser Middleware (cross-route global-state leak)
      // BodyParser(maxSize: N) permanently lowers the global decoder
      // cap, starving unrelated routes of their full body allowance.
      '16.01-bodyparser_global_maxbodysize_cross_route_leak',
      // # Encoder_
      // production handler must not execute before Content-Length body completion
      '17.01-handler_before_body_completion',
      // # TCP_Server_CLI Packages
      // nonblocking writes must not spin when fwrite() makes no progress
      '18.01-nonblocking_write_backpressure_spin',
      // # Decoder Downloading (multipart)
      // text fields must have independent memory caps
      '19.01-multipart_text_field_memory_cap',
      // # Request Header
      // field-name lookup must be case-insensitive (uppercase/mixed variants)
      '20.01-header_case_insensitivity',
      // # Request Session
      // unknown client-supplied IDs must be rotated before first write
      '21.01-session_strict_mode_unknown_id',
      // # Response Header
      // header names must be RFC token-validated and CR/LF-stripped
      '22.01-response_header_name_validation',
      // # Downloads
      // multipart downloads (server-side temp files materialised from
      // client uploads) must enforce an aggregate cross-worker
      // disk-byte ceiling — per-file cap × N-workers exhaustion is
      // closed by `Downloads::reserve()` over a shared counter
      '23.01-aggregate_downloads_disk_cap',
      // # Decoder_ cache (per-connection Request reuse)
      // `Request::assume()` must scrub ALL per-request state between
      // subsequent requests on the SAME keep-alive connection — behind a
      // keep-alive proxy, a same-connection leak is a cross-user leak.
      '24.01-decoder_cache_same_connection_request_reuse',
      // # Request line (protocol token)
      // unvalidated `protocol` token (audit F-1) — a bogus version
      // (`HTTP/9.9`) escapes BOTH the mandatory-Host guard and the
      // `$allowedHosts` allowlist and is still dispatched; must be
      // rejected `505 HTTP Version Not Supported` before framing.
      '25.01-request_line_protocol_token_not_validated',
      // # Connections (concurrency ceiling)
      // connection-exhaustion DoS (audit F-2) — Connections::connect() must
      // enforce a global (and opt-in per-IP) concurrent-connection ceiling.
      '26.01-connection_concurrency_ceiling',
      // # Decoder Chunked (slow-drip DoS + size cap)
      // audit F-6 — absolute decode deadline (not a per-packet sliding window)
      // and the chunked total-size cap must honor `Request::$maxBodySize`.
      '27.01-chunked_body_oversize_rejected',
      '27.02-chunked_absolute_deadline',
      // # Response JSONP (cross-origin read hardening)
      // audit F-7 — JSONP emits JavaScript and MUST be served as
      // `text/javascript` (+ `nosniff`), never `application/json`; the
      // callback name MUST be length-capped to bound the response prefix.
      '28.01-jsonp_javascript_content_type_and_nosniff',
      '28.02-jsonp_callback_length_capped',
      // # Downloads (crash-leak reconciliation)
      // audit F-10 — temp files + shared reservations leak when a worker dies
      // mid-request; reconcile() heals the counter from disk + sweep() removes
      // crash-orphaned files (older than ORPHAN_TTL).
      '29.01-downloads_reconcile_and_orphan_sweep',
      // realistic: a SIGKILLed worker leaks its reservation; the per-respawn
      // sweep()+reconcile() recovery heals the counter and removes the orphan.
      '29.02-downloads_worker_crash_recovery',
      // # Decoder Waiting (Content-Length overrun redispatch)
      // A body-shaped request followed by pipeline bytes must complete the
      // original POST; body bytes must never be decoded as another request.
      '30.01-content_length_body_overrun_redispatch',
      '30.02-content_length_partial_body_pipeline',
      '30.03-content_length_timeout_redispatch',
      // # Decoder Chunked (decoded payload length used as raw wire cursor)
      '31.01-chunked_payload_redispatch',
      '31.02-chunked_terminal_trailers',
      '31.03-chunked_timeout_redispatch',
      // # Decoder Downloading (multipart lifecycle — audit H7)
      // Initial body bytes must count toward completion; every abort and
      // deferred completion path must unlink temps and release reservations.
      '32.01-multipart_initial_feed_accounting',
      '32.02-multipart_lifecycle_cleanup',
      '32.03-multipart_timeout_destructor_cleanup',
      // # Decoder HTTP/2 (aggregate unfinished request-body retention — audit H8)
      '33.01-http2_aggregate_request_body_budget',
      '33.02-http2_worker_request_body_budget',
      // # Decoder Chunked (unbounded framing metadata retention — audit M1)
      '34.01-chunked_metadata_memory_cap',
      // # Decoder Downloading (aggregate text-field amplification — audit M2)
      '35.01-multipart_text_aggregate_amplification',
      '35.02-multipart_text_structured_storage',
      // # Response encoder (application framing headers — audit M3)
      // Application-supplied CL/TE must never survive beside the framing
      // selected and serialized by the HTTP/1 encoder.
      '36.01-response_application_framing_headers',
      '36.02-response_application_framing_modes',
      // # Router (route-group method inheritance — audit M4)
      // A methodless child must inherit the group's verb restriction; a GET
      // must never dispatch a handler declared inside a POST-only group.
      '37.01-router_group_method_restriction_bypass',
      '37.02-router_group_method_inheritance',
      '37.03-router_group_method_conflict_rejected',
      // # Session authentication (falsey identity acceptance — audit M5)
      // Present null/false identity values must fail before a protected
      // handler executes; key presence alone is not authentication.
      '38.01-session_null_false_identity_authentication',
      // # Session regeneration (CSRF secret lifecycle — audit M6)
      // Login/privilege rotation must invalidate both raw and masked forms
      // of the pre-regeneration synchronizer token.
      '39.01-session_regeneration_csrf_rotation',
      // The rotation invariant must not depend on an ordinary stoppable
      // listener or on the identity of the replaceable application event bus.
      '39.02-session_regeneration_csrf_equal_priority_stopper',
      '39.03-session_regeneration_csrf_emitter_replacement',
      // A transient CSRF owner must not silently remove rotation while its
      // configured token remains live in a Session across regeneration.
      '39.04-session_regeneration_csrf_weak_owner_lifecycle',
      // One failing invariant callback must not prevent later security
      // callbacks from completing the same privilege transition.
      '39.05-session_regeneration_csrf_regenerator_exception',
      // # Shared cache / RateLimit (CRC32 collision — audit M7)
      // Two independently limited custom principals must retain separate
      // counters even when their full sliding-window cache keys collide.
      '40.01-shared_cache_crc32_rate_limit_collision',
      // # TCP server Connection (partial-construction cleanup regression)
      // A destructor must not read TLS cleanup state that lacks a neutral
      // default when construction or a protocol-layer test double omits it.
      '41.01-partial_connection_destructor_safety',
      // # Route response cache (authority + declared Vary isolation — audit M8)
      // Cache hits execute before routing/middleware, so their lookup key must
      // represent the effective authority and every declared Vary dimension.
      '42.01-route_cache_authority_vary_isolation',
      // # File session storage (filesystem trust boundary — audit M9)
      // Existing save directories, final session-file modes and pre-positioned
      // signing secrets must fail closed under an attacker-influenced path.
      '43.01-session_file_filesystem_security',
      // # Response Pre resource (HTML-context encoding — audit M10)
      // Query-derived strings must be encoded before the helper places them
      // inside its default text/html response body.
      '44.01-response_pre_html_xss',
      // # Response Header preset (persistent response splitting — audit M11)
      // Names and values containing CR/LF must be rejected before persistent
      // preset state can serialize them into this or later responses.
      '45.01-response_header_preset_injection',
      // # Downloads aggregate cap (fail-closed infrastructure — audit M12)
      // A configured ceiling must not turn into an allow-all path when the
      // aggregate controller or advisory lock is unavailable.
      '46.01-downloads_reservation_fail_closed',
      // Each forked worker must reopen the aggregate-controller lockfile;
      // inherited flock descriptors are one lock owner on Linux.
      '46.02-downloads_worker_lock_isolation',
      // # connections diagnostic (raw-state disclosure + terminal injection + crash — audit M13)
      // Remote request bytes must never be rendered by the operator command,
      // and decoded protocol objects must be summarized without string casts.
      '47.01-connections_diagnostic_exposure_terminal_crash',
      // # Project control (mutable PID-state identity — audit M14)
      // An in-place state rewrite must never select an unrelated process for
      // reload/stop signaling without trusted process-identity validation.
      '48.01-project_command_mutable_pid_identity_signal',
      // # ACME advertised endpoint authority (HTTPS SSRF — audit M15)
      // Directory metadata must not make the client contact an unapproved
      // authority or a prohibited address class, even when HTTPS is used.
      '49.01-acme_advertised_url_authority_ssrf',
      // # Throwable reporter context (query-secret disclosure — audit L1)
      // A route exception must reach the configured reporter without copying
      // secret query values from the request target into its context.
      '50.01-throwable_report_query_secret_leak',
      // # RequestId middleware (correlation-label bounds — audit L2)
      // Client IDs must satisfy a conservative token grammar and length cap;
      // otherwise a fresh server-owned identifier must replace them.
      '51.01-request_id_token_length_bound',
      // # CLI terminal sizing (PATH execution before demotion — audit L3)
      // Invalid COLUMNS/LINES must not execute an attacker-selected `tput`
      // during the launch-credential CLI bootstrap.
      '52.01-tput_path_pre_demote_execution',
      // # TCP listener admission (abortive-close worker crash — audit C1)
      // A queued SO_LINGER reset must be rejected without allowing a partial
      // Connection to throw from the accept callback and terminate the worker.
      '53.01-abortive_tcp_close_worker_crash',
   ],
);

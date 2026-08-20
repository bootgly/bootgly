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

      if ( !defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php';
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
         '[2001:db8::1]',
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

      // ! Silence only the test traffic, never the boot. Muting before
      //   start() hid a listener refusal behind an empty exit(1) — the server
      //   would not come up and the suite reported nothing at all.
      Display::show(Display::NONE);

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
      // leading-dot filename sanitization (was never registered before DEC-1)
      '07.02-multipart_filename_leading_dot',
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
      // # RateLimit policy namespace + TTL ownership (audit 2026-08-02 M2)
      // A permissive short-window policy must not recreate a strict policy's
      // principal counter or weaken its independent 60-second quota.
      '40.02-rate_limit_policy_namespace_ttl_bypass',
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
      // # Route response cache (custom-header authentication bypass — audit C2)
      // A cache entry primed by a valid custom credential must never replay
      // its protected response before route authentication middleware runs.
      '54.01-route_cache_custom_header_auth_bypass',
      // Global admission middleware must likewise run before cache lookup.
      '54.02-route_cache_global_middleware_admission',
      // # Deferred routing context (cross-request authorization confusion — audit C3)
      // An authorized deferred handler must retain its own route/request
      // context while a denied dynamic request interleaves on the worker.
      '55.01-deferred_route_context_authorization_confusion',
      // # Multipart initial boundary (linear preamble retention — audit C4)
      // Missing or late first boundaries must not retain the complete
      // unauthenticated preamble while split valid boundaries remain accepted.
      '56.01-multipart_initial_boundary_preamble_retention',
      // # Response byte ranges (duplicate full-file amplification — audit C5)
      // Duplicate and overlapping ranges must be coalesced or rejected before
      // they multiply file reads, multipart metadata, and network egress.
      '57.01-duplicate_byte_range_response_amplification',
      // # Auto-TLS ownership handoff (privileged symlink TOCTOU — audit C6)
      // The pre-demote recursive handoff must never apply a root lchown()
      // through a path component the runtime identity can replace mid-walk.
      '58.01-autotls_privileged_ownership_symlink_toctou',
      // # Decoder Chunked (chunk-size overflow — audit M1)
      // A chunk-size token at or above 2^64 collapses to 0 through
      // hexdec()'s float and must not be read as the terminal chunk.
      '59.01-chunked_size_overflow_terminal_zero',
      // # Response defer over HTTP/2 (lost stream identity — audit M2)
      // A deferred response must keep its stream id, never serializing
      // HTTP/1 wire bytes into a live HTTP/2 connection.
      '60.01-http2_deferred_response_stream_identity',
      // # HTTP/2 rapid reset (flow-stalled bypass — audit M4)
      // A stream whose response is only PARTIALLY emitted must still
      // charge its peer reset to the CVE-2023-44487 budget.
      '61.01-http2_flow_stalled_reset_budget_bypass',
      // # HTTP/2 outbound backlog (aggregate budget — audit M3)
      // Parked response bodies must be bounded per connection, not one
      // whole body per concurrent flow-stalled stream.
      '62.01-http2_outbound_backlog_aggregate_budget',
      // # HTTP/1 head grammar + size cap (audit N1, N2)
      // Field-names must be RFC tchar, every nonempty head line must
      // carry a colon, and the 16 KiB cap must not depend on packetization.
      '63.01-http1_head_grammar_and_size_cap',
      // # Response header identity + octets (audit M8)
      // Field identity is case-insensitive, and every insertion path
      // must reject forbidden C0/DEL octets, not only preset insertion.
      '64.01-response_header_case_identity_and_octets',
      // # Route cache identity partition (audit 2026-07-27 H1)
      // Two DIFFERENT valid principals admitted by GLOBAL middleware must
      // never share a cached response: the key omits the admitted identity.
      '65.01-route_cache_global_identity_partition',
      // # Host authority grammar (audit 2026-07-27 M1)
      // RFC 9110 7.2 Host is `uri-host [":" port]` and never userinfo:
      // `allowed:@evil` must not reduce to the allowed name.
      '66.01-host_authority_userinfo_allowlist_bypass',
      // # ACME HTTP-01 helper redirect (audit 2026-07-27 L2)
      // The target is appended to a trusted authority, so only
      // origin-form may be reflected into the 308 Location.
      '67.01-acme_helper_location_origin_form',
      // # Header-block memo credential retention (audit 2026-07-27 L4)
      // The memo is keyed on the RAW block, so an Authorization/Cookie
      // line would sit in worker memory as the key itself.
      '68.01-parser_memo_credential_retention',
      // # Route cache directives (audit 2026-07-27 M5)
      // no-store/private must prevent storage and request no-cache must
      // force the handler path, over any route-level TTL.
      '69.01-route_cache_control_directives',
      // # View export scope (audit 2026-07-27 M2)
      // View is a persistent resource: its export() map must not carry
      // one request's data into the next on the same worker.
      '70.01-view_export_request_scope',
      // # Session Cache key path ancestors
      // Key creation/import resolve the path in separate steps, so a
      // replaceable ancestor must be refused, not just the leaf.
      '71.01-session_cache_key_path_ancestors',
      // # ACME problem details reaching the log renderer
      // A CA-supplied type/detail must not carry control bytes or
      // Bootgly markup into the formatted log line.
      '72.01-acme_problem_detail_log_injection',
      // # HTTP/2 flow-progress deadline
      // A parked response body must be bounded by TIME too: a peer can
      // defer the idle reaper with PINGs while never granting credit.
      '73.01-http2_flow_progress_deadline',
      // # HTTP/1 aggregate request-body budget
      // The per-request cap bounds one body; nothing bounds their sum, and
      // neither body decoder released on close — HTTP/2 has both controls.
      '74.01-http1_aggregate_request_body_budget',
      // # Route-cache lifecycle output ownership (audit 2026-07-27 M1)
      // Current-request middleware/event output must never be replaced by a
      // previously cached request's raw header or body representation.
      '75.01-route_cache_lifecycle_per_request_replay',
      // # Route-cache Received-event preset ownership (audit 2026-07-28 M1)
      // A persistent preset changed for the current request before reset must
      // not be replaced by raw wire cached under the previous request's value.
      '75.02-route_cache_received_preset_replay',
      // # HTTP/1 file-response backpressure (audit M2)
      // A stalled multi-chunk upload must retain its disk cursor and finish
      // the advertised body before a later keep-alive response reaches wire.
      '76.01-http1_file_stream_backpressure_state_loss',
      // # Multipart boundary delimiter classification (audit M3)
      // An anchored boundary token with a malformed suffix must reject before
      // it can manufacture a hidden field or hand off a truncated file.
      '77.01-multipart_boundary_delimiter_validation',
      // # Route-cache Early Hints adoption (audit M4)
      // A leading informational response is not the final response head:
      // materializing a lifecycle-aware hit must parse the following 200.
      '78.01-route_cache_early_hints_adoption',
      // # Executable redirect schemes split by control bytes (audit M5)
      // Query decoding must not let CR/LF/HTAB hide a dangerous scheme from
      // redirect() before Location serialization canonicalizes the value.
      '79.01-response_redirect_control_byte_scheme_bypass',
      // # Route-cache aggregate byte budget (audit M6)
      // Distinct query targets on one eligible route must not retain one
      // independently admissible wire each without a per-worker byte ceiling.
      '80.01-route_cache_aggregate_byte_budget',
      // # TCP retained output aggregate byte budget (audit L1)
      // Independently admissible per-connection pending buffers must not
      // multiply past one configurable worker-wide retained-byte ceiling.
      '81.01-tcp_worker_retained_byte_budget',
      // Exact-cap, cross-owner, carry, shrink and cleanup invariants for the
      // retained-byte ledger introduced by the L1 remediation.
      '81.02-tcp_retained_byte_ledger_lifecycle',
      // Separate HTTP/2 connections must share the same worker authority and
      // release it on RST_STREAM before fresh admission.
      '81.03-http2_worker_retained_byte_budget',
      // # HTTP/2 file-descriptor aggregation (audit H2)
      // One byte of flow-control credit per file stream must not retain enough
      // independent handlers to invalidate the worker's select() backend.
      '82.01-http2_file_descriptor_selector_exhaustion',
      // Reopening per positive flow-control quantum must reject atomic
      // pathname identity changes without parking the file descriptor.
      '82.02-http2_file_reopen_identity',
      // # Half-open HTTP/2 request-head aggregation (audit H3)
      // Valid near-limit decoded heads retained without END_STREAM must share
      // one worker authority across independent connections.
      '83.01-http2_half_open_request_head_accounting',
      // Incomplete request Streams have a separate absolute monotonic
      // lifetime that PING activity and one-byte DATA progress cannot renew.
      '83.02-http2_incomplete_request_deadline',
      // # Multipart pad deferred-write ownership (audit L2)
      // A generated prepend/append must move to pendingBuffer exactly once
      // when an exact-cap public Range upload short- or zero-writes.
      '84.01-multipart_pad_deferred_write_ownership',
      // # File session revocation (audit 2026-08-01 H1)
      // A stale request loaded before regenerate() must never recreate the
      // invalidated authenticated ID through its later save().
      '85.01-session_stale_writer_revocation',
      // The default Shared Cache session backend must enforce the same
      // stale-writer revocation invariant on its cross-worker store.
      '85.02-session_cache_stale_writer_revocation',
      // # HTTP/1.0 Transfer-Encoding persistence (audit 2026-08-01 M1)
      // Transfer coding belongs to HTTP/1.1 request framing. HTTP/1.0 must
      // reject it and close before terminal-chunk bytes can become a follower.
      '86.01-http10_chunked_request_persistence',
      // # Privileged startup upload cleanup (audit 2026-08-01 H2)
      // A runtime-replaceable downloaded directory must never let the
      // pre-demote orphan sweep delete files through a selected symlink.
      '87.01-downloads_privileged_startup_symlink_deletion',
      // # Deferred Fiber selector admission (audit 2026-08-01 H3)
      // Readiness scheduling must reject the same unrepresentable descriptor
      // as guarded add(), without poisoning unrelated low-FD progress.
      '88.01-deferred_fiber_selector_descriptor_admission',
      // # Process identity ownership (audit 2026-08-01 M2)
      // A default outbound client created in a forked server worker must not
      // overwrite that server Process or signal inherited sibling entries.
      '89.01-process_role_outbound_client_corruption',
      // # Mutable TrustedProxy trust provenance (audit 2026-08-01 L1)
      // A second proxy-resolution pass must authenticate the immutable socket
      // peer rather than the application-facing address changed by a prior pass.
      '90.01-trusted_proxy_mutable_address_trust_confusion',
      // # Deferred-write wall-time enforcement (audit 2026-08-02 L2)
      // A permanently non-writable peer must be closed by an independent
      // monotonic deadline without waiting for another EVENT_WRITE callback.
      '91.01-tcp_nonwritable_write_deadline',
      // # Worker listener descriptor admission (audit 2026-08-01 L3)
      // A worker must fail startup before entering its event loop when the
      // inherited listener cannot be represented by the select backend.
      '92.01-tcp_worker_listener_descriptor_admission',
      // # HTTP/1 file reopen identity (audit 2026-08-02 M1)
      // A streamed file response must validate every reopen — the first one
      // included — against the identity captured before its head was built,
      // so a pathname swapped while the response is parked cannot supply the
      // body.
      '93.01-http1_file_reopen_identity',
      // # HEAD file responses (audit 2026-08-02, found while closing M1)
      // A HEAD must carry the Content-Length a GET would and no body at all;
      // the buffered-body suppression cannot reach disk-backed bytes.
      '94.01-http1_head_file_response_body',
      // # Server-initiated range semantics (audit 2026-08-02, found while closing M1)
      // `upload($file, $offset, $length)` must describe the bytes it actually
      // sends, and refuse a window the file cannot satisfy.
      '95.01-http1_server_range_semantics',
      // # Route cache vs streamed responses (audit 2026-08-02 M4)
      // A file response's body never passes through stash(); storing its head
      // alone would answer every warm hit with a Content-Length and no body.
      '96.01-route_cache_streamed_response_storage',
      // # Route cache entry framing (audit 2026-08-02 M4, class-level)
      // Whatever produced it, a stored entry must carry exactly the body its
      // head advertises — otherwise a warm hit desyncs the connection.
      '96.02-route_cache_entry_framing',
      // # Shared-cache expired-record reclamation (audit 2026-08-02 M3)
      // Expired RateLimit principals must release fixed SysV capacity without
      // requiring an operator to call purge() before the next request.
      '97.01-rate_limit_shared_expired_capacity',
      // # ACME HTTP-01 continuity across daemon reload (audit 2026-08-02 L1)
      // The Gate may stay bound, but validation is unavailable if its only
      // responder is killed before application workers finish draining.
      '98.01-acme_http01_reload_continuity',
      // # HTTP-01 handoff degradation (audit 2026-08-12 L1, follow-up)
      // import() runs on the fresh image AFTER the old one drained and exec'd
      // away, so a false return there is an outage, not a refusal. An
      // unauthenticated or incompatible inherited helper must be retired and
      // the Gate adopted, never traded for exit(1).
      '98.02-acme_helper_handoff_degradation',
      // # Stale ACME swap namespaces (audit 2026-08-02 L2)
      // Reaped AutoTLS owner namespaces must be reclaimed without treating a
      // short-lived publisher as the owner or deleting a live sibling.
      '99.01-acme_swap_stale_namespace_reclamation',
      // # Deferred swap publication privilege boundary (L2 remediation)
      // Root configuration cannot traverse a runtime-owned swap tree; the
      // namespace lease and records are first created by the runtime identity.
      '99.02-acme_swap_deferred_privileged_publication',
      // # CSRF same-origin IPv6 authority parsing (audit 2026-08-02 L3)
      // A bracketed IPv6 Host must remain intact when its optional port is
      // removed; splitting at the first colon rejects a valid same-origin POST.
      '100.01-csrf_ipv6_literal_same_origin',
      // # Deferred response Telemetry lifecycle (audit 2026-08-02 L4)
      // A completed deferred response must emit the same terminal accounting
      // as a synchronous response: total, in-flight, duration and status class.
      '101.01-telemetry_deferred_lifecycle',
      // # Deferred Telemetry correlation/error regressions (L4 remediation)
      // Interleaved sync/deferred work and a Catcher 500 must each close the
      // correct exchange exactly once without leaking the in-flight gauge.
      '101.02-telemetry_deferred_interleaving_error',
      // # Deferred disconnect/pooled-Fiber Telemetry regressions (L4)
      // Peer teardown closes core accounting without a synthetic status; the
      // later pooled-Fiber job must carry an independent exchange lifecycle.
      '101.03-telemetry_deferred_disconnect_pool',
      // # Inverse deferred completion Telemetry regression (L4 remediation)
      // Two parked exchanges complete B-before-A and retain independent
      // duration, total, gauge and status-class accounting.
      '101.04-telemetry_deferred_inverse_completion',
      // # Exceptional Telemetry terminal paths (L4 remediation)
      // Received-listener and Response-resource reset throws preserve their
      // cancellation semantics; reversible Session-save failure becomes one
      // clean 500 while all three close core accounting exactly once.
      '101.05-telemetry_exceptional_lifecycle',
      // # Fresh selected Response Telemetry paths (L4 remediation)
      // A distinct handler Response(201) and Catcher Response(500) must each
      // terminalize their sole admitted exchange before another request.
      '101.06-telemetry_response_selection',
      // # Deferred selection boundaries (L4 remediation)
      // Cloned/deferred/SSE responses selected from handler, Handled or a
      // deferred callback must own one lifecycle and emit one final head.
      '101.07-telemetry_deferred_selection_boundaries',
      // # HTTP/2 HEAD + SSE terminal status (L4 remediation)
      // The SSE-selected 200 HEADERS+END_STREAM must be the only stream wire
      // and the sole Telemetry status, even after a stale application 409.
      '101.08-telemetry_http2_head_sse',
      // # Nested defer / pooled Fiber generation (L4 remediation)
      // A nested child is the sole selected response; its late completion
      // cannot cancel a later job reusing the parked parent Fiber.
      '101.09-telemetry_nested_defer_generation',
      // # Legacy scheduler / upload rollback (L4 remediation)
      // Contextualizing-only schedulers remain compatible when unobserved;
      // observed rejection restores upload ownership before encoder cleanup.
      '101.10-telemetry_legacy_scheduler_upload_rollback',
      // # Retained stale Response clone / lazy SSE (L4 remediation)
      // A completed clone cannot emit an SSE head after its HTTP/1.1 socket
      // begins the next exchange; active and sibling deferred clones retain
      // one scheduler generation and one selected wire.
      '101.11-telemetry_stale_clone_sse',
      // # Nested handoff parent wait (L4 remediation)
      // After a suspended child becomes the selected generation, wait() on
      // the still-running terminal parent is a no-op and cannot compete.
      '101.12-telemetry_nested_handoff_wait',
      // # Deferred encode failure (L4 remediation)
      // Serialization must succeed before terminal selection; an encode throw
      // produces one Catcher 500 wire and one matching 5xx lifecycle.
      '101.13-telemetry_deferred_encode_error',
      // # Deferred HTTP/2 HEAD + SSE ownership order (L4 remediation)
      // The SSE-selected 200 lifecycle must finish before Stream teardown can
      // cancel its attached deferred generation and erase the terminal status.
      '101.14-telemetry_deferred_http2_head_sse',
      // # Stoppable Received priority isolation (L4 remediation)
      // A pre-boot maximum-priority application listener may stop the public
      // event bus without blinding the admitted core Telemetry lifecycle.
      '101.15-telemetry_received_priority_isolation',
      // # Synchronous response serialization failure (L4 remediation)
      // An encode() throw preserves its original no-wire Throwable boundary
      // and closes total/duration/in-flight without selecting a status.
      '101.16-telemetry_synchronous_encode_error',
      // # Orphan deferred Response boundaries (L4 remediation)
      // A raw deferred flag or fresh unbound replacement suppresses wire but
      // still terminalizes its admitted exchange with no synthetic status.
      '101.17-telemetry_orphan_deferred_response',
      // # Nested scheduler rejection upload rollback (L4 remediation)
      // A rejected child restores its upload to the running parent before the
      // caught LogicException and sole 202 lifecycle clean it exactly once.
      '101.18-telemetry_nested_scheduler_rejection_upload',
      // # Handled post-selection Throwable containment (L4 remediation)
      // Once deferred/SSE output selected a wire, a later listener cannot
      // escape the reactor, revoke that lifecycle or append a stale response.
      '101.19-telemetry_handled_post_selection_throw',
      // # Received pre-reset Response clone tombstone (L4 remediation)
      // A listener may retain the reusable Response after admission but before
      // reset; that clone must carry the newly admitted Exchange through its
      // completed request and reject late SSE on the reused connection.
      '101.20-telemetry_received_clone_tombstone',
      // # Reused Request active-Exchange isolation (L4 remediation)
      // An unobserved request admitted while an older deferred exchange is
      // active must own a fresh SSE lifecycle and cannot finish that exchange.
      '101.21-telemetry_reused_request_exchange_isolation',
      // # Lazy synchronous Exchange fast path (L4 performance remediation)
      // A fresh unobserved emitter must keep both Exchange registries absent;
      // an admission observer still receives one bound and terminal 201 token.
      '101.22-lazy_synchronous_exchange_fast_path',
      // # HTTP/2 pre-reset Response clone context (L4 remediation)
      // A clone retained by Received for stream B must carry B's Package,
      // Request/stream and Exchange without inheriting deferred A's Cancellation.
      '101.23-http2_prereset_clone_context',
      // # Deferred HTTP/2 interleaved Catcher context (L4 remediation)
      // A fresh Catcher response after a deferred handler/encode throw must
      // terminate A/1 with 500, not an ambient B/3 request or B's Exchange.
      '101.24-deferred_http2_interleaved_catcher_context',
      // # Escape-detection blindness on a returned replacement Response
      // The encoder assigns a handler-returned Response through its
      // `&Server::$Response` alias, so the gate's `Server::$Response`
      // operand aliases the replacement and can never fire.
      '101.25-escape_replacement_response_split',
      // A carried resource keeps pointing at the response that mounted it,
      // while defer() opens the generation on the clone — so an SSE mounted
      // under a non-default name silently refuses to open.
      '101.26-sse_mounted_resource_generation',
      // # Session persistence failure containment (audit 2026-08-20 H1)
      // Fixed-size Shared cache exhaustion must become one bounded request
      // failure, never an uncaught worker exit and supervisor refork loop.
      '102.02-session_cache_capacity_worker_crash',
      // # Cross-protocol inbound body budget (audit 2026-08-20 M6)
      // HTTP/1 and HTTP/2 unfinished bodies share one worker-wide byte cap.
      '102.03-cross_protocol_worker_body_budget',
      // # Project state namespace collision (audit 2026-08-20 M2)
      // Nested and literal-tilde project names must never resolve the same
      // authenticated master state or deliver a cross-project control signal.
      '102.06-project_state_namespace_collision',
   ],
);

<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\E2E;


use const BOOTGLY_ROOT_DIR;
use const SIGKILL;
use const SIGTERM;
use const STDERR;
use const STDIN;
use const STDOUT;
use const STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
use const STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const WNOHANG;
use function define;
use function defined;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getppid;
use function posix_kill;
use function posix_setsid;
use function str_repeat;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function strlen;
use function strpos;
use function substr;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ Define BOOTGLY_PROJECT for upload tests (requires project path)
      if ( !defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // @ Scripted upstream fixtures (BG-13 probes) — forked BEFORE start() so
      //   the port constants are inherited by the server workers and the
      //   children carry no server state. One serial accept loop per
      //   listener: cleartext (8098) and TLS (8102), each in its own child.
      // ? 8098 predates this map and is shared with ACME_Challenge (the suites
      //   run serially); 8102 — 8081-8097 belong to the other E2E suites,
      //   8099 to ACME_Swap, 8100 to ACME_E2E, 8101 to E2E_DualStack and
      //   8103 to E2E_Idle and 8104 to E2E_Deferred.
      if ( !defined('BOOTGLY_E2E_UPSTREAM_PORT') ) {
         define('BOOTGLY_E2E_UPSTREAM_PORT', 8098);
      }
      if ( !defined('BOOTGLY_E2E_UPSTREAM_TLS_PORT') ) {
         define('BOOTGLY_E2E_UPSTREAM_TLS_PORT', 8102);
      }
      $serve = static function (int $port, null|array $TLS): void {
         // ! Own session: the @test choreography signals the process group;
         //   the fixture must only die by the explicit teardown kill below
         posix_setsid();
         $context = $TLS === null
            ? stream_context_create()
            : stream_context_create(['ssl' => $TLS]);
         $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
         );
         // ! Release the inherited stdio pipes: a failing suite exits fail-fast
         //   WITHOUT running this autoboot's teardown, and any harness wrapper
         //   draining the runner's stdout would wait forever on a pipe this
         //   child still holds.
         fclose(STDIN);
         fclose(STDOUT);
         fclose(STDERR);
         if ($server === false) {
            exit(1);
         }
         $parent = posix_getppid();

         $flakes = 0;

         // @@ Serial scripted responses — the E2E specs run serially
         while (true) {
            // ? Orphan watchdog — the fail-fast exit above also skips the
            //   teardown reap; die with the runner instead of squatting the port
            if (posix_getppid() !== $parent) {
               exit(0);
            }
            $conn = @stream_socket_accept($server, 1);
            if ($conn === false) {
               continue;
            }
            // @ TLS listener: handshake before reading the head
            if ($TLS !== null) {
               $crypto = @stream_socket_enable_crypto(
                  $conn,
                  true,
                  STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
               );
               if ($crypto !== true) {
                  @fclose($conn);
                  continue;
               }
            }

            // ! Read the request head
            $head = '';
            while (strpos($head, "\r\n\r\n") === false) {
               $bytes = @fread($conn, 4096);
               if ($bytes === '' || $bytes === false) {
                  break;
               }
               $head .= $bytes;
            }
            $path = '/';
            $space = strpos($head, ' ');
            if ($space !== false) {
               $end = strpos($head, ' ', $space + 1);
               if ($end !== false) {
                  $path = substr($head, $space + 1, $end - $space - 1);
               }
            }

            // @ Respond per path
            switch ($path) {
               case '/delay':
                  // ? 1 s upstream latency — the BG-13 stall window
                  usleep(1_000_000);
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 13\r\nConnection: close\r\n\r\nhello-bg13-ok");
                  break;
               case '/never':
                  // ? Accept and never answer — hold until the peer gives up (10 s cap)
                  stream_set_blocking($conn, false);
                  $held = 0;
                  while (feof($conn) === false && $held < 100) {
                     usleep(100_000);
                     @fread($conn, 4096);
                     $held++;
                  }
                  break;
               case '/leave':
                  // ? Cross-origin redirect to a dead origin
                  @fwrite($conn, "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:1/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                  break;
               case '/downgrade':
                  // ? https → http step-down: refused by default (R3 remainder)
                  @fwrite($conn, "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:" . BOOTGLY_E2E_UPSTREAM_PORT . "/fast\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                  break;
               case '/grow':
                  // ? Oversized body — pairs with a small maxResponseBytes
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 262144\r\nConnection: close\r\n\r\n" . str_repeat('A', 262144));
                  break;
               case '/chunk':
                  // ? Malformed chunked framing
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\nZZ\r\nbogus\r\n0\r\n\r\n");
                  break;
               case '/burst':
                  // ? Keep-alive + three back-to-back writes (three TLS records):
                  //   no EOF rescues a reader that went back to select with
                  //   bytes still buffered in the TLS layer. Held open until
                  //   the client lets go (its deferral end), 3 s cap.
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 32\r\nConnection: keep-alive\r\n\r\n");
                  @fwrite($conn, 'burst-record-one');
                  @fwrite($conn, 'burst-record-two');
                  stream_set_blocking($conn, false);
                  $held = 0;
                  while (feof($conn) === false && $held < 30) {
                     usleep(100_000);
                     @fread($conn, 4096);
                     $held++;
                  }
                  break;
               case '/flaky/reset':
                  // ? Decouples specs from the process-global counter
                  $flakes = 0;
                  @fwrite($conn, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                  break;
               case '/flaky':
                  // ? Fails once (connection closed without a response), then
                  //   answers with the hit count so specs can pin the retry
                  $flakes++;
                  if ($flakes === 1) {
                     break;
                  }
                  $body = "flaky-ok-{$flakes}";
                  $length = strlen($body);
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n{$body}");
                  break;
               case '/fast':
                  // ? Instant answer — pairs with /delay in interleaved batches
                  @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 7\r\nConnection: close\r\n\r\nfast-ok");
                  break;
               default:
                  @fwrite($conn, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            }

            @fclose($conn);
         }
      };
      $certificates = BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/';
      $upstream = pcntl_fork();
      if ($upstream === -1) {
         throw new RuntimeException('HTTP Server E2E could not fork the upstream fixture.');
      }
      if ($upstream === 0) {
         $serve(BOOTGLY_E2E_UPSTREAM_PORT, null);
         exit(0);
      }
      $secured = pcntl_fork();
      if ($secured === -1) {
         throw new RuntimeException('HTTP Server E2E could not fork the TLS upstream fixture.');
      }
      if ($secured === 0) {
         $serve(BOOTGLY_E2E_UPSTREAM_TLS_PORT, [
            'local_cert' => "{$certificates}localhost.cert.pem",
            'local_pk' => "{$certificates}localhost.key.pem"
         ]);
         exit(0);
      }

      HTTP_Server_CLI::pretest($Suite);

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         new HTTP_Server_CLI\Configs(
            host: '0.0.0.0',
            // ? 8097 — off the contested 8080 (Docker containers/dev servers
            //   commonly bind it on the host) and outside the 8081-8096 range
            //   already claimed by the other E2E suites.
            port: 8097,
            workers: 1,
            health: '/health'
         ),
         // @ BG-13 zero-boilerplate resources — built lazily in the worker,
         //   one embedded client per deferral (Resources::fork() rebuilds them)
         new HTTP_Server_CLI\Response\Configs(Resources: [
            'Upstream' => static fn (object $Context): HTTP => new HTTP(
               host: '127.0.0.1',
               port: BOOTGLY_E2E_UPSTREAM_PORT
            ),
            'Mirror' => static fn (object $Context): HTTP => new HTTP(
               host: '127.0.0.1',
               port: BOOTGLY_E2E_UPSTREAM_PORT,
               pool: ['min' => 0, 'max' => 2]
            ),
            // ? Real verification: the E2E_SSL certificate carries
            //   `IP:127.0.0.1` in its SAN, so the auto peer_name matches
            'Secure' => static fn (object $Context): HTTP => new HTTP(
               host: '127.0.0.1',
               port: BOOTGLY_E2E_UPSTREAM_TLS_PORT,
               secure: ['cafile' => "{$certificates}localhost.cert.pem"]
            ),
         ])
      );

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: reap the scripted upstream fixtures FIRST, so the server's
      //   own child reaping below never blocks on an untracked process
      $reap = static function (int $child): void {
         posix_kill($child, SIGTERM);
         $reaped = 0;
         for ($attempt = 0; $attempt < 20; $attempt++) {
            $reaped = pcntl_waitpid($child, $status, WNOHANG);
            if ($reaped === $child || $reaped === -1) {
               break;
            }
            usleep(50_000);
         }
         if ($reaped !== $child && $reaped !== -1) {
            posix_kill($child, SIGKILL);
            pcntl_waitpid($child, $status);
         }
      };
      $reap($upstream);
      $reap($secured);

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      'Request/' => [
         '1.01-request_as_response-address',
         '1.02-request_as_response-port',
         '1.03-request_as_response-scheme',
         '1.04-request_as_response-raw',
         '1.05-request_as_response-method',
         '1.06-request_as_response-uri',
         '1.07-request_as_response-protocol',
         '1.08-request_as_response-url',
         '1.10-request_as_response-query',
         '1.11-request_as_response-queries',
         '1.12-request_as_response-basic-auth',
         '1.12.1-request_as_response-bearer-auth',
         '1.13-request_as_response-headers',
         '1.14.1-request_as_response-header-host',
         '1.14.2-request_as_response-header-host',
         '1.14.3-request_as_response-header-host',
         '1.14.4-request_as_response-header-host',
         '1.14.5-request_as_response-header-ips',
         '1.15.1-request_as_response-header-accept_language',
         '1.15.2-request_as_response-header-accept_language-quality',
         '1.16.1-request_as_response-header-cookies',
         '1.17.1-request_as_response-content-contents',
         '1.17.2-request_as_response-content-contents',
         '1.17.3-request_as_response-content-contents',
         '1.17.4-request_as_response-content-contents',
         '1.18.1-request_as_response-content-post',
         '1.18.2-request_as_response-content-post',
         '1.18.3-request_as_response-content-post',
         // Body parsing is dispatched on the media type, not on the method
         // (REQ-1) — .4 is the POST control read straight from `fields`.
         '1.18.4-request_as_response-content-post',
         '1.18.5-request_as_response-content-put',
         '1.18.6-request_as_response-content-patch',
         '1.18.7-request_as_response-content-delete',
         // Reading `input` must not clobber the streaming decoder's fields
         // (REQ-2).
         '1.18.8-request_as_response-content-post-input',
         '1.19.1-request_as_response-content-post-file',
         '1.19.2-request_as_response-content-post-file',
         '1.19.3-request_as_response-content-post-file',
         // Streaming Decoder (multipart/form-data → disk)
         '1.20.1-request_as_response-content-streaming-file',
         '1.20.2-request_as_response-content-streaming-file',
         '1.20.3-request_as_response-content-streaming-file',
         '1.20.4-request_as_response-content-streaming-file',
         '1.20.5-request_as_response-content-streaming-file',
         '1.20.6-request_as_response-content-streaming-file',
         '1.20.7-request_as_response-content-streaming-file',
         // Streaming Decoder → Storage disk (upload persistence)
         '1.20.8-request_store-persist',
         // Streaming Decoder — empty file input is no-file (DEC-1)
         '1.20.9-request_as_response-content-streaming-empty_input',
         '1.21-request_as_response-on',
         '1.22-request_as_response-at',
         '1.23-request_as_response-time',
         // HTTP/1.1 Caching Specification (RFC 7234)
         '2.1.1-request_cache-if-modified-since',
         '2.1.2-request_cache-if-modified-since',
         '2.1.3-request_cache-if-modified-since',
         '2.1.4-request_cache-if-modified-since',
         '2.1.5-request_cache-if-modified-since',
         '2.2.1-request_cache-if-none-match',
         '2.2.2-request_cache-if-none-match',
         '2.2.3-request_cache-if-none-match',
         '2.2.4.1-request_cache-if-none-match-etag_weak',
         '2.2.4.2-request_cache-if-none-match-etag_weak',
         '2.2.5-request_cache-if-none-match-etag_strong',
         '2.2.6.1-request_cache-if-none-match-catch_all',
         '2.2.6.2-request_cache-if-none-match-catch_all',
         '2.3.1-request_cache-if-none-match-if-modified-since',
         '2.3.2-request_cache-if-none-match-if-modified-since',
         '2.3.3-request_cache-if-none-match-if-modified-since',
         '2.3.4-request_cache-if-none-match-if-modified-since',

         '2.4-request_cache-no-cache',
         // Session
         '3.2-request_session',
         '3.2.01-request_session-method-set_get',
         '3.2.02-request_session-method-has',
         '3.2.03-request_session-method-delete',
         '3.2.04-request_session-method-pull',
         '3.2.05-request_session-method-put',
         '3.2.06-request_session-method-forget',
         '3.2.08-request_session-method-list',
         '3.2.09-request_session-method-flush',
         '3.2.10-request_session-method-check',
         // HTTP/1.1 Compliance (RFC 9110/9112)
         '4.01-request_compliance-host_header_required',
         '4.02-request_compliance-host_header_valid',
         '4.03-request_compliance-http10_no_host',
         '4.04-request_compliance-trace_501',
         '4.05-request_compliance-connect_501',
         '4.06-request_compliance-unknown_method_405',
         '4.07-request_compliance-head_no_body',
         '4.08-request_compliance-te_cl_conflict',
         '4.09-request_compliance-unknown_te_501',
         '4.10-request_compliance-connection_close',
         '4.11-request_compliance-chunked_single',
         '4.12-request_compliance-chunked_multi',
         '4.13-request_compliance-expect_100_continue',
         '4.14-request_compliance-expect_417',
         '4.15-request_compliance-uri_too_long',
         // HTTP/1.0 backward compatibility (RFC 9110 §2.5)
         '4.16-request_compliance-http10_status_line',
         '4.17-request_compliance-http10_chunked_disable',
         // Request-line / framing strictness (audit F-1)
         '4.18-request_compliance-bare_lf_rejected',
         '4.19-request_compliance-unsupported_version_505',
         // Fixture injection
         '5.1-request_fixture-injection',
         // Accept-Language full language-ranges (appended last — index stability)
         '1.15.3-request_as_response-header-accept_language-ranges',
         '1.15.4-request_as_response-header-accept_language-long_range',
         '1.15.5-request_as_response-header-accept_language-exclusions',
      ],
      'Response/' => [
         '1.1-respond_with_a_simple_hello_world',
         // ! Meta
         // status
         '1.2-respond_with_status_302_using_send',
         '1.2.1-respond_with_status_500_no_body',
         // ! Header
         // Content-Type
         '1.3-respond_with_content_type_text_plain',
         // ? Header \ Cookie
         '1.4-respond_with_header_set_cookies',
         // ! Content
         // @ send
         '1.5-send_content_in_json_using_resources',
         '1.5.1-send_content_in_plaintext_using_resources',
         '1.5.2-type_default_crlf_injection_stripped',
         // @ authenticate
         '1.6-authenticate_with_http_basic_authentication',
         '1.6.1-authenticate_with_unknown_authentication_method',
         // @ redirect
         '1.7-redirect_to_bootgly_docs',
         '1.7.2-redirect_with_302_to_bootgly_docs',
         // @ View
         '1.8-render_view_with_inheritance',
         // @ negotiate (JSON/XML/HTML content negotiation + F-12 guard)
         '1.9-negotiate_json',
         '1.9.1-negotiate_xml',
         '1.9.2-negotiate_html',
         '1.9.3-negotiate_not_acceptable',
         '1.9.4-negotiate_default_json',
         '1.9.5-view_traversal_forbidden',
         '1.9.6-view_default_layout',
         '1.9.7-negotiate_quality_order',
         '1.9.8-negotiate_refused_all',
         // @ upload
         // .1 - Small Files
         '1.z.1-upload_a_small_file',
         // .2.1 - Requests Range - Dev
         '1.z.2.1-upload_file_with_offset_length_1',
         // .2.2 - Requests Range - Client - Single Part (Valid)
         '1.z.2.2.1-upload_file_with_range-requests_1',
         '1.z.2.2.2-upload_file_with_range-requests_2',
         '1.z.2.2.3-upload_file_with_range-requests_3',
         '1.z.2.2.4-upload_file_with_range-requests_4',
         '1.z.2.2.5-upload_file_with_range-requests_5',
         // 2.3 - Requests Range - Client - Single Part (Invalid)
         '1.z.2.3.1-upload_file_with_invalid_range-requests_1',
         '1.z.2.3.2-upload_file_with_invalid_range-requests_2',
         '1.z.2.3.3-upload_file_with_invalid_range-requests_3',
         '1.z.2.3.4-upload_file_with_invalid_range-requests_4',
         '1.z.2.3.5-upload_file_with_invalid_range-requests_5',
         '1.z.2.3.6-upload_file_with_invalid_range-requests_6',
         // 2.4 - Requests Range - Client - Multi (Valid)
         '1.z.2.4.1-upload_file_with_multi_range-requests_1',
         // .3 - Large Files
         '1.z.3-upload_large_file',
         // @ catch (environment-aware error responses — appended last to keep
         //   the X-Bootgly-Test indices of the specs above stable)
         '1.13.1-catch_test_env_legacy_500',
         '1.13.2-catch_development_debug_page',
         '1.13.3-catch_development_negotiate_json',
         '1.13.4-catch_production_clean_page',
         '1.13.5-catch_production_custom_view',
         '1.13.6-catch_production_localized_page',
         '1.13.7-catch_production_locale_reset',
         // @ i18n Vary token-awareness + q=0 exclusions — run while the
         //   catalog roots registered by 1.13.6 are still loaded
         '1.13.8-i18n_vary_tokens',
         '1.13.9-i18n_negotiation_exclusions',
         // @ i18n worker-state cleanup — MUST run right after the i18n specs:
         //   drops the catalog roots they registered so the byte-exact specs
         //   below run without the automatic Vary: Accept-Language
         '1.13.10-i18n_state_cleanup',
         // @ SSE (Server-Sent Events — appended last to keep the
         //   X-Bootgly-Test indices of the specs above stable)
         '3.1-sse_head_and_events',
         '3.2-sse_last_event_id_and_retry',
         '3.3-sse_heartbeat_ping',
         '3.4-sse_pipelining_guard',
         // @ 103 Early Hints + 308 status-map regression (appended last)
         '2.10-hint_early_hints',
         '2.11-hint_http10_noop',
         '2.12-redirect_permanent_308',
         // @ Built-in health endpoint (appended last)
         '4.1-health_endpoint',
         '4.2-health_bypasses_middlewares',
         // @ SSE hardening
         '3.5-sse_content_length_conflict',
         // @ HTTP niceties
         '2.13-hint_noop_edges',
         '2.14-hint_route_cache_replay',
         '4.3-health_head_request',
         '4.4-health_beats_route_cache',
         // @ SSE hardening
         '3.6-sse_head_method',
         '3.7-sse_tick_throw',
         // @ SSE hardening
         '3.8-sse_cache_control_merge',
         // @ SSE hardening
         '3.9-sse_cache_control_exact',
         '3.10-sse_cache_control_quoted',
         // @ HTTP niceties hardening
         '2.15-hint_route_cache_http10_guard',
         '2.16-code_1xx_rejected',
         // @ end() preset (RES-1 — appended last to keep the X-Bootgly-Test
         //   indices of the specs above stable)
         '1.2.2-end_preserves_status_and_headers',
         '1.z.2.3.7-upload_file_with_malformed_range',
      ],
      'Router/' => [
         '1.1-route_callback-closure',
         '1.2-route_callback-function',
         '1.3-route_callback-static_method',
         #'2.1-route_condition-method-custom',
         '2.2.1-route_condition-method-multiple',
         '2.2.2-route_condition-method-multiple',
         '2.2.3-route_condition-method-multiple',
         '2.3-route_condition-method_agnostic',
         '3.0-route_route-specific',
         // Named params
         '3.1-route_route-single_param_id',
         '3.2.1-route_route-multiple_param-different',
         '3.2.2-route_route-multiple_param-equals',
         '3.2.3-route_route-multiple_param-equals-different',
         #'3.3.1-route_route-multiple_param-equals-different',
         // Param constraint types
         '3.4.1-route_route-param_constraint_int_valid',
         '3.4.2-route_route-param_constraint_int_invalid',
         '3.4.3-route_route-param_constraint_slug_valid',
         '3.4.4-route_route-param_constraint_alpha_valid',
         '3.4.5-route_route-param_constraint_alpha_invalid',
         '3.4.6-route_route-param_constraint_alphanum_valid',
         '3.4.7-route_route-param_constraint_alphanum_invalid',
         '3.4.8-route_route-param_constraint_slug_invalid',
         '3.4.9-route_route-param_constraint_uuid_valid',
         '3.4.10-route_route-param_constraint_uuid_invalid',
         // In-URL inline regex
         '3.5.1-route_route-inurl_regex_valid',
         '3.5.2-route_route-inurl_regex_invalid',
         // Route group
         '4.1-route_group-unparameterized',
         '4.2-route_group-parameterized',
         '4.3-route_group-middleware_intercept',
         '4.4-route_group-nested_absolute_path_rejected',
         // Route Catch-All
         '5.1-route_catch_all-generic',
         // Route Catch-All parameterized
         '5.2-route_catch_all-parameterized_single',
         '5.3-route_catch_all-parameterized_multi',
         // Root without a static match falls through to the catch-all
         '5.4-route_catch_all-root_without_static',
         // Route registration errors (warmup-time guards)
         '6.1-route_error-catch_all_not_last',
         '6.2-route_error-unknown_constraint_type',
         // Route response cache (cache: TTL opt-in)
         '7.1-route_cache-hit',
         '7.2-route_cache-no_opt_in',
         '7.3-route_cache-credentialed_bypass',
         '7.4-route_cache-query_keys',
         '7.5-route_cache-language_variance',
         '7.6-route_cache-queued_set_cookie_guard',
         '7.7-route_cache-middleware_variance',
         '7.8-route_cache-global_lifecycle_guard',
         '7.9-route_cache-received_lifecycle_guard',
      ],
      'Router/Middleware/' => [
         // CORS
         '1.1-middleware-cors',
         '1.2-middleware-cors_preflight',
         // SecureHeaders
         '2.1-middleware-secure_headers',
         // RequestId
         '3.1-middleware-request_id',
         '3.2-middleware-request_id_preserved',
         // ETag
         '4.1-middleware-etag',
         '4.2-middleware-etag_304',
         // Compression
         '5.1-middleware-compression',
         // BodyParser
         '6.1-middleware-body_parser',
         '6.2-middleware-body_parser_413',
         // RateLimit
         '7.1-middleware-rate_limit',
         // TrustedProxy
         '8.1-middleware-trusted_proxy',
         // Validator
         '9.1-middleware-validator',
         '9.2-middleware-validator_headers',
         '9.3-middleware-validator_headers_in',
         '9.4-middleware-validator_cookies',
         '9.5-middleware-validator_cookies_in',
         '9.6-middleware-validator_cookies_lines',
         // Authentication
         '10.1-middleware-authentication_bearer_success',
         '10.2-middleware-authentication_bearer_failure',
         '10.3-middleware-authentication_jwt_success',
         '10.4-middleware-authentication_basic_success',
         '10.5-middleware-authentication_jwt_expired_failure',
         '10.6-middleware-authentication_jwt_tampered_failure',
         '10.7-middleware-authentication_jwt_alg_failure',
         '10.8-middleware-authentication_jwt_malformed_failure',
         '10.9-middleware-authentication_session_missing_failure',
         '10.9.1-middleware-authentication_session_missing_key_failure',
         '10.10-middleware-authentication_basic_wrong_failure',
         '10.11-middleware-authentication_basic_malformed_failure',
         '10.12-middleware-authentication_basic_missing_colon_failure',
         '10.13-middleware-authentication_authorization_fuzz_failure',
         '10.14-middleware-authentication_metadata_reset',
         // Authorization
         '11.1-middleware-authorization_scope_success',
         '11.2-middleware-authorization_scope_failure',
         '11.3-middleware-authorization_role_success',
         '11.4-middleware-authorization_role_failure',
         '11.5-middleware-authorization_policy_success',
         '11.6-middleware-authorization_policy_failure',
         '11.7-middleware-authorization_rbac_e2e',
      ],
      'Response/Sequential/' => [
         // Response state reset across sequential requests
         '1.1-response_body_reset_across_routes',
         '1.2-response_status_code_reset_across_routes',
         '1.3-response_header_reset_across_routes',
         // Idempotency
         '1.4-response_idempotent_same_route',
         // Header content-cache isolation (no cross-request contamination)
         '1.5-response_header_cache_content_length_isolation',
         '1.6-response_header_cache_hit_repeated',
         '1.7-response_header_cache_alternating_no_leak',
         // Default media type (Header->type) reset + cache-key isolation
         '1.8-response_type_default_no_leak',
         // Worker-bound Response survives a thrown route (Catcher alias break)
         '1.9-response_error_bound_response_recovery',
         // Route params reset per request (static after dynamic; duplicates)
         '1.10-route_params_reset_static_after_dynamic',
         '1.11-route_duplicate_params_reset_across_requests',
         // Upload + normal request interleaving
         '2.1-response_upload_then_normal_request',
         '2.2-response_normal_then_upload_request',
         '2.3-response_upload_then_different_upload',
      ],
      'Response/Scheduled/' => [
         '1.1-scheduled_sync_baseline',
         '1.2-scheduled_deferred_response',
         '1.3-scheduled_deferred_concurrent',
         '1.4-scheduled_deferred_io_aware',
         '1.5-scheduled_deferred_hybrid',
         '1.6-scheduled_deferred_http_request',
         '1.7-scheduled_deferred_ordering',
         '1.8-scheduled_deferred_readiness_write',
         '1.9-scheduled_deferred_response_isolation',
         '1.10-scheduled_deferred_removed_readiness',
         '1.11-scheduled_deferred_i18n',
         '1.12-scheduled_deferred_native_client',
         '1.13-scheduled_embedded_failures',
         '1.14-scheduled_embedded_isolation',
         '1.15-scheduled_resource_native_client',
         '1.16-scheduled_resource_interleaved',
         '1.17-scheduled_resource_tls',
         '1.18-scheduled_resource_tls_failures',
      ],
      'Queues/' => [
         '1.1-http-enqueue',
      ]
   ]
);

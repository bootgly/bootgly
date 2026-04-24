<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\Security;

use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Logger::$display = Logger::DISPLAY_NONE;

      if ( !defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo-HTTP_Server_CLI/Demo-HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      sleep(3); // @ Ensure the previous suite's worker processes have terminated and released state locks.

      HTTP_Server_CLI::pretest($Suite, 'Security');

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

      return true;
   },
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
   ],
);

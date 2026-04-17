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
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/Demo.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

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
      // # Request input()
      // Content-Type confusion — parse_str() fallthrough on invalid JSON
      //   Placed FIRST: every subsequent Security test couples to a shared
      //   per-worker handler queue (multi-request arrays, side-sockets,
      //   decode-reject slot-pops). 12.01 is self-contained (single request,
      //   no side connection, no middleware) so it belongs outside that
      //   coupling — any position inside the chain destabilises 4.01, 8.01
      //   or 11.01 by stealing a handler slot.
      '12.01-input_content_type_confusion_parse_str_fallthrough',
      // # Decoder
      // cross-connection state bleed
      '1.01-decoder_chunked_cross_connection_state',
      '1.02-decoder_downloading_cross_connection_state',
      '1.03-decoder_waiting_stale_timestamp',
      // L1 cache cross-connection Request reuse
      '2.01-decoder_cache_shared_request_across_connections',
      // # Request
      // Content-Length validation
      '3.01-content_length_negative_accepted',
      '3.02-content_length_strict_parse_bypass',
      // # Request Session
      // file handler unserialise without integrity
      '4.01-session_file_unserialize_forgery',
      // # Decoder Chunked
      // blind 2-byte CRLF consumption → TE smuggling
      '5.01-chunked_decoder_blind_crlf_consumption',
      // # Decoder Downloading (multipart)
      // boundary not validated (charset + length cap) → injection & algorithmic DoS
      '6.01-multipart_boundary_injection_and_oversize',
      // # Decoder_ cache
      // shallow clone → sub-object (Header/Body/Cookies) state bleed
      '7.01-decoder_cache_shallow_clone_subobject_bleed',
      // # Request Expect
      //   Listed before 8.01 so 7.01's priming-connection queue-drain
      //   (7.01 opens 2 side sockets + 1 harness request against a queue
      //   with only 1 entry per test — see 3.01 comment) consumes 9.01 /
      //   9.02 entries here, leaving 8.01's harness request to inherit the
      //   handler cached by 9.01 (the last entry popped). 9.01 / 9.02
      //   reject at decode time, so their handler is never invoked.
      // `100 Continue` written before body-size validation (TE+Expect unbounded)
      '9.01-expect_100_continue_with_te_chunked',
      // `100 Continue` written before oversized Content-Length is rejected
      '9.02-expect_100_continue_with_oversized_content_length',
      // # Response
      // path traversal via sibling-prefix bypass (str_starts_with w/o trailing sep)
      '8.01-response_path_traversal_sibling_prefix_bypass',
      // # Request Host
      // Host-header spoofing — allowlist enforcement
      '10.01-host_header_allowlist_spoofing',
      // # BodyParser Middleware
      // limit bypass — maxSize not pushed to decode-time gate
      '11.01-bodyparser_limit_bypass_decode_time',
   ],
);

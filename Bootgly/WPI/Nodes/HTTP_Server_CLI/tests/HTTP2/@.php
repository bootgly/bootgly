<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function fclose;
use function fopen;
use function ftruncate;
use function is_file;
use function str_repeat;
use function unlink;
use function usleep;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }
      $largeFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/statics/h2-s4-large.bin';
      if (is_file($largeFile) === false) {
         $Handler = fopen($largeFile, 'wb');
         if ($Handler !== false) {
            ftruncate($Handler, 17 * 1024 * 1024);
            fclose($Handler);
         }
      }

      // @ Boot the HTTP server in Test mode with a fixed echo handler —
      //   the HTTP/2 client specs speak raw frames (prior knowledge).
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8085,
         workers: 1,
         health: '/health'
      );
      // ! SSE teardown observability — Close hooks stamp these flags; the
      //   report routes read them (same worker: workers = 1)
      $hooked = 'pending';
      $holdHooks = 0;
      $capSent = 'pending';
      $capHooks = 0;
      $HTTP_Server_CLI->on(
         Events::RequestReceived,
         function ($Request, Response $Response) use (&$hooked, &$holdHooks, &$capSent, &$capHooks): Response {
            if ($Request->URI === '/h2-s4-large') {
               return $Response->upload('statics/h2-s4-large.bin', close: false);
            }

            if ($Request->URI === '/hints') {
               $Response->hint('</app.css>; rel=preload; as=style');
            }

            // @ 1xx as a final status must FAIL LOUD — after the throw the
            //   exchange still terminates as a normal 200 with END_STREAM
            if ($Request->URI === '/code-1xx') {
               try {
                  $Response->code(103);
               }
               catch (InvalidArgumentException) {
                  // ! Expected: informational codes never become final
               }

               return $Response->send('final');
            }

            if ($Request->URI === '/sse') {
               $SSE = $Response->SSE;
               $SSE->heartbeat = 0;

               $SSE->open();
               $SSE->send('h2', event: 'tick', id: '1');
               $SSE->close();

               return $Response;
            }

            // @ Backlog cap: a window-starved stream must be RST, not grow —
            //   the breached send() must report false and run Close once
            if ($Request->URI === '/sse-cap') {
               $SSE = $Response->SSE;
               $SSE->heartbeat = 0;

               $SSE->open(Close: static function () use (&$capHooks): void {
                  $capHooks++;
               });
               $capSent = $SSE->send(str_repeat('x', 5 * 1024 * 1024)) ? 'true' : 'false';

               return $Response;
            }

            if ($Request->URI === '/sse-cap-report') {
               return $Response->send("sent={$capSent};hooks={$capHooks}");
            }

            // @ Aggregate backlog: each event fits the per-connection budget
            //   alone, but parked siblings count against it
            if ($Request->URI === '/sse-agg') {
               $SSE = $Response->SSE;
               $SSE->heartbeat = 0;

               $SSE->open();
               $SSE->send(str_repeat('a', 3 * 1024 * 1024));

               return $Response;
            }

            // @ Drain watchdog: a graceful close() with parked bytes must
            //   still be bounded in time — shrink the stall deadline so the
            //   spec observes the reset without the production 30s wait
            if ($Request->URI === '/sse-drain') {
               TCP_Server_CLI::$maxWriteWallTime = 1;

               $SSE = $Response->SSE;
               $SSE->heartbeat = 1; // 1s supervisor cadence

               $SSE->open();
               $SSE->send(str_repeat('d', 65536));
               $SSE->close(); // parked backlog → draining watchdog

               return $Response;
            }

            if ($Request->URI === '/sse-drain-restore') {
               TCP_Server_CLI::$maxWriteWallTime = 30;

               return $Response->send('restored');
            }

            // @ Stall-clock progress: a producer that keeps parking while
            //   the peer keeps draining must NOT be reset — and a fully
            //   drained backlog must never poison the next one with a
            //   stale stall timestamp. Heartbeat off: the spec counts
            //   exact event bytes per drain generation.
            if ($Request->URI === '/sse-repark') {
               TCP_Server_CLI::$maxWriteWallTime = 2;

               $SSE = $Response->SSE;
               $SSE->heartbeat = 0;

               $SSE->open(Tick: static function ($SSE): void {
                  $SSE->send(str_repeat('r', 32768));
               }, interval: 1);

               return $Response;
            }

            if ($Request->URI === '/sse-repark-restore') {
               TCP_Server_CLI::$maxWriteWallTime = 30;

               return $Response->send('restored');
            }

            // @ Teardown hook: stream stays open until the client resets it.
            //   The hook THROWS after stamping — protocol cleanup must
            //   complete anyway (contained at the SSE boundary)
            if ($Request->URI === '/sse-hold') {
               $SSE = $Response->SSE;
               $SSE->heartbeat = 0;

               $SSE->open(Close: static function () use (&$hooked, &$holdHooks): void {
                  $holdHooks++;
                  $hooked = 'closed';
                  throw new RuntimeException('close-hook-failure');
               });

               return $Response;
            }

            if ($Request->URI === '/sse-hook') {
               return $Response->send("{$hooked};count={$holdHooks}");
            }

            return $Response->send("method={$Request->method};uri={$Request->URI};protocol={$Request->protocol};body={$Request->input}");
         }
      );
      // ! These specs exercise the real dispatch pipeline, not the
      //   index-based test harness — swap `Encoder_Testing` (installed by
      //   Modes::Test) for the canonical `Encoder_` before the worker forks.
      HTTP_Server_CLI::$Encoder = new Encoder_;

      $HTTP_Server_CLI->start();
      // @ Let the forked worker bind before the client specs connect.
      usleep(400000);

      // @ Run the self-contained client specs against the live server.
      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         // @ Teardown: terminate workers and release the state lock so the next
         //   suite in the same master process can bind/lock cleanly.
         $HTTP_Server_CLI->Process->stopping = true;
         $HTTP_Server_CLI->Process->Children->terminate();
         $HTTP_Server_CLI->Process->State->clean();
         if (is_file($largeFile)) {
            unlink($largeFile);
         }
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-preface_settings',
      '1.2-malformed',
      '1.3-protocol_errors',
      '2.1-requests',
      '2.2-multiplex',
      '2.3-validation',
      '3.1-curl',
      '4.1-hardening',
      '4.2-compliance',
      '4.3-file_streaming',
      '4.4-feeding_contract',
      '5.1-throughput',
      '5.2-h2spec',
      '6.1-sse_stream',
      '6.2-early_hints',
      '6.3-health',
      '6.4-sse_backpressure_cap',
      '6.5-sse_rst_close_hook',
      '6.6-sse_aggregate_backlog_cap',
      '6.7-sse_head_method',
      '6.8-sse_drain_deadline',
      '6.9-sse_stall_progress',
      '6.10-code_1xx_rejected'
   ]
);

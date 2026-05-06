<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\Fuzz;

use function defined;
use function sleep;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite;
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

      HTTP_Server_CLI::pretest($Suite, 'Fuzz');

      // @ Ensure Middlewares pipeline is initialized before any request reaches
      //   Encoder_ — fuzz specs open side connections that may race the @test
      //   init signal to the worker.
      if ( ! isset(SAPI::$Middlewares)) {
         SAPI::$Middlewares = new Middlewares;
      }

      // ! Single worker is intentional — fuzz invariants exercise per-worker
      //   state to keep failure reproduction deterministic.
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
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      // @ Property-based / fuzz tests built on top of an HTTP grammar.
      //   Each spec generates N requests from a generator + invariant pair;
      //   determinism is enforced via a per-spec seed so failures reproduce.
      // # Header casing & ordering
      '24.01-header_casing_ordering_invariants',
      // # Pipelining (CL + chunked mix)
      '24.02-pipelined_cl_chunked_mix',
      // # Slow body trickling
      '24.03-slow_body_trickling',
      // # Multipart shape fuzzing
      '24.04-multipart_shape_fuzz',
      // # Degenerate framing
      '24.05-degenerate_framing',
   ],
);

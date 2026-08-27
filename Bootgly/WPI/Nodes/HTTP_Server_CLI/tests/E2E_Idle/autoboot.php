<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\E2E_Idle;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      HTTP_Server_CLI::pretest($Suite, 'E2E_Idle');

      // ! configure() runs in this master process: the shortened reaper must
      //   not leak into the suites that follow
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldDeferred = Response::$deferredTimeout;
      try {
         $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
         $HTTP_Server_CLI->configure(
            host: '0.0.0.0',
            // ? 8103 — 8081-8097 belong to the other E2E suites, 8098 to
            //   ACME_Challenge (and the E2E upstream fixture), 8099 to ACME_Swap,
            //   8100 to ACME_E2E, 8101 to E2E_DualStack and 8102 is the E2E TLS
            //   upstream fixture.
            port: 8103,
            workers: 1,
            health: '/health',
            // ! The idle reaper under test, shortened so a parked deferral
            //   outlives it within a spec: a reap lands in [N, N+1) s after the
            //   last activity tick on the one-second timer wheel
            connectionIdleTimeout: 2
         );

         $HTTP_Server_CLI->start();

         $HTTP_Server_CLI->Commands->command('test');

         // @ Teardown: terminate workers and release state lock so the next
         //   suite running in the same master PHP process can bind/lock cleanly.
         $HTTP_Server_CLI->Process->stopping = true;
         $HTTP_Server_CLI->Process->Children->terminate();
         $HTTP_Server_CLI->Process->State->clean();
      }
      finally {
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         Response::$deferredTimeout = $oldDeferred;
      }

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-h1_first_request_park',
      '1.2-h1_prior_write_park',
      '1.3-h2_park',
      '1.4-idle_reap_after_deferral',
      '1.5-deferred_timeout_global',
      '1.6-deferred_timeout_per_call_catch',
      '1.7-deferred_timeout_own_answer',
      '1.8-client_leaves_mid_park',
      '1.9-deferred_timeout_disarmed'
   ]
);

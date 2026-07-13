<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\ACME_Challenge;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function getmypid;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
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

      // ! Shared challenge-token dir — planted by the specs, read by workers
      $challenges = sys_get_temp_dir() . '/bootgly-acme-challenge-e2e-' . getmypid() . '/';
      if (is_dir($challenges) === false) {
         mkdir($challenges, 0755, true);
      }
      Challenges::configure($challenges);

      // @ Boot a plain HTTP server — the built-in HTTP-01 responder must win
      //   over any user handler/middleware (health-probe rationale).
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      try {
         $HTTP_Server_CLI->configure(
            host: '0.0.0.0',
            port: 8098,
            workers: 1
         );
         $HTTP_Server_CLI->on(
            Events::RequestReceived,
            function ($Request, Response $Response): Response {
               return $Response->send('handler');
            }
         );
         // ! These specs exercise the real dispatch pipeline, not the
         //   index-based test harness.
         HTTP_Server_CLI::$Encoder = new Encoder_;

         $HTTP_Server_CLI->start();
         // @ Let the forked worker bind before the client specs connect.
         usleep(400000);

         // @ Run the self-contained client specs against the live server.
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

         Challenges::configure(null);

         if (is_dir($challenges)) {
            foreach (glob("{$challenges}*") ?: [] as $file) {
               unlink($file);
            }
            rmdir($challenges);
         }
      }

      // ? Nested assertion failures MUST fail the outer run — the aggregate
      //   runner treats any non-throwing autoBoot as passed
      if ($Suite->failed > 0) {
         throw new RuntimeException(
            "ACME_Challenge E2E: {$Suite->failed} nested case(s) failed."
         );
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-challenge'
   ]
);

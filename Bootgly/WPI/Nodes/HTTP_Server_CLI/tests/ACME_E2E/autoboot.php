<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\ACME_E2E;


use const BOOTGLY_ROOT_DIR;
use const SIGTERM;
use function define;
use function defined;
use function fclose;
use function fsockopen;
use function getenv;
use function getmypid;
use function is_dir;
use function is_resource;
use function posix_kill;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // ? Opt-in live suite: requires BOOTGLY_ACME_E2E=1 + a Pebble test CA:
      //   docker run --rm --net=host -e PEBBLE_VA_NOSLEEP=1 \
      //      ghcr.io/letsencrypt/pebble -config test/config/pebble-config.json
      //   (Pebble's default config validates HTTP-01 on port 5002.)
      //   When unavailable the specs skip themselves (declarative `skip:`,
      //   the MySQL-live pattern) — $GLOBALS carries the live context.
      $optin = getenv('BOOTGLY_ACME_E2E') === '1';
      if ($optin === false) {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();

         return true;
      }

      // ? Explicit opt-in with an unreachable Pebble FAILS — a CI job that
      //   requested the live suite but failed to start the CA must never
      //   stay green on a silent skip
      $Probe = @fsockopen('127.0.0.1', 14000, $code, $message, 0.5);
      if (is_resource($Probe) === false) {
         throw new RuntimeException(
            'ACME_E2E: BOOTGLY_ACME_E2E=1 was set but Pebble is not reachable on 127.0.0.1:14000.'
         );
      }
      fclose($Probe);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // ! Fresh Auto-TLS storage per run; everything unprivileged: the
      //   HTTP-01 gate binds Pebble's validation port (5002).
      $storage = sys_get_temp_dir() . '/bootgly-acme-pebble-e2e-' . getmypid() . '/';

      $AutoTLS = new AutoTLS(
         domains: ['localhost'],
         email: 'acme-e2e@bootgly.com',
         directory: 'https://localhost:14000/dir',
         path: $storage,
         port: 5002,
         verify: false,
         allowPrivate: true,
         options: ['verify_peer' => false]
      );

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      try {
         $HTTP_Server_CLI->configure(
            host: '0.0.0.0',
            port: 8100,
            workers: 1,
            secure: $AutoTLS
         );
         $HTTP_Server_CLI->on(
            Events::RequestReceived,
            function ($Request, Response $Response): Response {
               return $Response->send('secure');
            }
         );
         // ! Real dispatch pipeline.
         HTTP_Server_CLI::$Encoder = new Encoder_;

         $HTTP_Server_CLI->start();
         // @ Let the forked worker bind before the client specs connect.
         usleep(400000);

         // ! Expose the live server + config to the specs.
         $GLOBALS['BOOTGLY_ACME_PEBBLE'] = [
            'Server' => $HTTP_Server_CLI,
            'AutoTLS' => $AutoTLS,
            'storage' => $storage
         ];

         // @ Run the self-contained client specs against the live server.
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         // @ Teardown: terminate workers, the HTTP-01 helper and release the
         //   state lock so the next suite can bind/lock cleanly.
         $HTTP_Server_CLI->Process->stopping = true;
         $HTTP_Server_CLI->Process->Children->terminate();
         if ($HTTP_Server_CLI->helper > 0) {
            posix_kill($HTTP_Server_CLI->helper, SIGTERM);
         }
         $HTTP_Server_CLI->Process->State->clean();

         Challenges::configure(null);
         unset($GLOBALS['BOOTGLY_ACME_PEBBLE']);

         // @ Remove the per-run credential tree
         if (is_dir($storage)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
            }
            rmdir($storage);
         }
      }

      // ? Nested assertion failures MUST fail the outer run — the aggregate
      //   runner treats any non-throwing autoBoot as passed
      if ($Suite->failed > 0) {
         throw new RuntimeException(
            "ACME_E2E: {$Suite->failed} nested case(s) failed."
         );
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-pebble'
   ]
);

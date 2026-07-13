<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\ACME_Swap;


use const BOOTGLY_ROOT_DIR;
use const SIGTERM;
use function define;
use function defined;
use function getmypid;
use function is_dir;
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

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // ! Fresh Auto-TLS storage per run
      $storage = sys_get_temp_dir() . '/bootgly-acme-swap-e2e-' . getmypid() . '/';

      // @ Boot a TLS server on the typed AutoTLS config: first boot forges
      //   the self-signed bootstrap and binds immediately; the HTTP-01 gate
      //   (port 8078, unprivileged) forks the persistent helper.
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      try {
         $HTTP_Server_CLI->configure(
            host: '0.0.0.0',
            port: 8099,
            workers: 2,
            secure: new AutoTLS(
               domains: ['localhost'],
               email: 'acme-e2e@bootgly.com',
               path: $storage,
               port: 8078,
               options: ['verify_peer' => false]
            )
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
         // @ Let both forked workers bind before the client specs connect.
         usleep(400000);

         // ! Expose the live server to the specs (swap trigger + storage path).
         $GLOBALS['BOOTGLY_ACME_SWAP'] = [
            'Server' => $HTTP_Server_CLI,
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
         unset($GLOBALS['BOOTGLY_ACME_SWAP']);

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
            "ACME_Swap E2E: {$Suite->failed} nested case(s) failed."
         );
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-swap',
      '1.2-daemon',
      '1.3-interactive',
      '1.4-lease',
      '1.5-orphan',
      '1.6-startup',
      '1.7-certifier'
   ]
);

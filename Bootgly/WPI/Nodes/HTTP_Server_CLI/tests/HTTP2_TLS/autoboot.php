<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2_TLS;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function usleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

require_once __DIR__ . '/../HTTP2/Client.php';


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // ! Test-only security budgets inherited by the forked worker. Restore
      //   the master statics during teardown so neighboring suites keep the
      //   production defaults.
      $savedHandshakeTimeout = HTTP_Server_CLI::$handshakeTimeout;
      $savedMaxPendingHandshakes = HTTP_Server_CLI::$maxPendingHandshakes;
      HTTP_Server_CLI::$handshakeTimeout = 0.75;
      HTTP_Server_CLI::$maxPendingHandshakes = 2;

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // @ Boot a TLS server with ALPN h2 (default when `secure` is set).
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8086,
         workers: 1,
         secure: [
            'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
            'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',
            'verify_peer' => false,
         ]
      );
      $HTTP_Server_CLI->on(
         Events::RequestReceived,
         function ($Request, Response $Response): Response {
            return $Response->send("method={$Request->method};uri={$Request->URI};protocol={$Request->protocol};scheme={$Request->scheme}");
         }
      );
      // ! These specs exercise the real dispatch pipeline, not the
      //   index-based test harness (see the HTTP2 suite twin).
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

         HTTP_Server_CLI::$handshakeTimeout = $savedHandshakeTimeout;
         HTTP_Server_CLI::$maxPendingHandshakes = $savedMaxPendingHandshakes;
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-alpn',
      '2.1-silent_handshake_worker_block',
      '2.2-silent_handshake_deadline',
      '2.3-pending_handshake_ceiling',
      '2.4-fragmented_client_hello',
   ]
);

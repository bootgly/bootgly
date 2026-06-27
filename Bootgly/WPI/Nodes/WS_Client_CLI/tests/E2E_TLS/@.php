<?php

namespace Bootgly\WPI\Nodes\WS_Client_CLI\tests\E2E_TLS;


use const BOOTGLY_ROOT_DIR;
use const STREAM_CLIENT_CONNECT;
use function define;
use function defined;
use function fclose;
use function stream_context_create;
use function stream_socket_client;
use function usleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/WS_Server_CLI/WS_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // @ Boot a wss:// server with the bundled localhost certificate.
      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8087,
         workers: 1,
         secure: [
            'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
            'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',
            'verify_peer' => false,
         ],
         heartbeatInterval: 0
      );
      $WS_Server_CLI->on(Events::MessageReceived, function ($Session, $Message) {
         // @ Out-of-band echo preserves opcode/binary/empty exactly.
         $Session->send($Message->payload, $Message->binary);

         return '';
      });

      $WS_Server_CLI->start();
      // @ Readiness probe — complete a REAL TLS handshake (verify off). A plain-TCP
      //   probe would wedge the single-worker TLS server (it blocks in
      //   enable_crypto(SERVER) on a peer that never speaks TLS). Polls until the
      //   listener accepts, instead of a fixed sleep.
      $probeContext = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
      for ($i = 0; $i < 200; $i++) {
         $probe = @stream_socket_client('tls://127.0.0.1:8087', $errno, $errstr, 0.2, STREAM_CLIENT_CONNECT, $probeContext);
         if ($probe !== false) {
            fclose($probe);
            break;
         }
         usleep(25000);
      }

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         $WS_Server_CLI->Process->stopping = true;
         $WS_Server_CLI->Process->Children->terminate();
         $WS_Server_CLI->Process->State->clean();
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-tls'
   ]
);

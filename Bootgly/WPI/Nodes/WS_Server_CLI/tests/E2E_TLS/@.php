<?php

namespace Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E_TLS;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function usleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


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

      // @ Boot a TLS (wss://) server with the bundled localhost certificate.
      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8089,
         workers: 1,
         secure: [
            'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
            'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',
            'verify_peer' => false,
         ],
         heartbeatInterval: 0
      );
      $WS_Server_CLI->on(Events::MessageReceived, function ($Session, $Message) {
         return "echo: {$Message->payload}";
      });

      $WS_Server_CLI->start();
      // @ Let the forked worker bind before the client specs connect.
      usleep(400000);

      // @ Point the shared client at the TLS server for these specs.
      Client::$tls = true;
      Client::$port = 8089;

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         // @ Restore the client defaults + tear the server down.
         Client::$tls = false;
         Client::$port = 8084;

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

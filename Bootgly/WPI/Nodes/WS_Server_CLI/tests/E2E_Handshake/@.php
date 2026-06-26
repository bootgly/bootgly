<?php

namespace Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E_Handshake;


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

      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/WS_Server_CLI/WS_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8088,
         workers: 1,
         heartbeatInterval: 0
      );
      // @ Custom upgrade predicate (Events::HandshakeRequested): admit only an
      //   allowlisted Origin — the canonical WS anti-CSWSH guard.
      $WS_Server_CLI->on(Events::HandshakeRequested, function ($Request) {
         return $Request->Header->get('Origin') === 'http://allowed';
      });
      $WS_Server_CLI->on(Events::MessageReceived, function ($Session, $Message) {
         return $Message->payload;
      });

      $WS_Server_CLI->start();
      usleep(400000);

      Client::$port = 8088;

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
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
      '1.1-handshake'
   ]
);

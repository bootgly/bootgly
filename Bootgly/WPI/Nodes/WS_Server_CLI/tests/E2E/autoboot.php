<?php

namespace Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
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

      // @ Boot the WebSocket server in Test mode with a fixed echo + lobby handler.
      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8084,
         workers: 1,
         heartbeatInterval: 0
      );
      $WS_Server_CLI
         ->on(Events::Connected, function ($Session) {
            $Session->join('lobby');
         })
         ->on(Events::MessageReceived, function ($Session, $Message) {
            $Session->broadcast('lobby', $Message->payload);

            return "echo: {$Message->payload}";
         });

      $WS_Server_CLI->start();
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
      '1.1-handshake',
      '2.1-messaging',
      '3.1-validation',
      '4.1-channels'
   ]
);

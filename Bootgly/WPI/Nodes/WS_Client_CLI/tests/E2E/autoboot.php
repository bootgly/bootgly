<?php

namespace Bootgly\WPI\Nodes\WS_Client_CLI\tests\E2E;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function fclose;
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

      // @ Boot a live WebSocket server (Test mode) with a pure-echo handler so the
      //   framework client specs can round-trip text/binary/compressed frames.
      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8094,
         workers: 1,
         heartbeatInterval: 0
      );
      $WS_Server_CLI->on(Events::MessageReceived, function ($Session, $Message) {
         // @ Out-of-band echo preserves opcode/binary/empty exactly.
         $Session->send($Message->payload, $Message->binary);

         return '';
      });

      $WS_Server_CLI->start();
      // @ Readiness probe — poll until the forked worker accepts a TCP connection,
      //   instead of a fixed sleep (de-flakes under slow / parallel CI).
      for ($i = 0; $i < 200; $i++) {
         $probe = @stream_socket_client('tcp://127.0.0.1:8094', $errno, $errstr, 0.05);
         if ($probe !== false) {
            fclose($probe);
            break;
         }
         usleep(25000);
      }

      // @ Run the WS_Client_CLI specs against the live server.
      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         // @ Teardown: terminate the worker and release the state lock so the next
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
      '1.1-messaging',
      '2.1-fragmentation',
      '3.1-multiclient'
   ]
);

<?php

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\tests\HTTP2_E2E;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function str_repeat;
use function usleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
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

      // @ Boot the real HTTP server (h2c prior-knowledge on by default) —
      //   the HTTP_Client_CLI specs negotiate HTTP/2 over cleartext.
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8087,
         workers: 1
      );
      $HTTP_Server_CLI->on(
         Events::RequestReceived,
         function ($Request, Response $Response): Response {
            // @ Same-origin redirect target pair
            if ($Request->URI === '/redirect') {
               $Response->code(302);
               $Response->Header->set('Location', '/landing');

               return $Response->send('');
            }
            if ($Request->URI === '/landing') {
               return $Response->send('landed');
            }

            // @ Large body — forces client-side recv WINDOW_UPDATE replenishes
            if ($Request->URI === '/large') {
               return $Response->send(str_repeat('L', 200000));
            }

            // @ Echo
            return $Response->send("method={$Request->method};uri={$Request->URI};protocol={$Request->protocol};body={$Request->input}");
         }
      );
      // ! These specs exercise the real dispatch pipeline, not the
      //   index-based test harness (see the HTTP_Server_CLI HTTP2 suite twin).
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
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-get',
      '1.2-post_body',
      '1.3-head_no_body',
      '2.1-multiplex',
      '2.2-large_download',
      '3.1-redirect'
   ]
);

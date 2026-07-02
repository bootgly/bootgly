<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function fclose;
use function fopen;
use function ftruncate;
use function is_file;
use function unlink;
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
      $largeFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/statics/h2-s4-large.bin';
      if (is_file($largeFile) === false) {
         $Handler = fopen($largeFile, 'wb');
         if ($Handler !== false) {
            ftruncate($Handler, 17 * 1024 * 1024);
            fclose($Handler);
         }
      }

      // @ Boot the HTTP server in Test mode with a fixed echo handler —
      //   the HTTP/2 client specs speak raw frames (prior knowledge).
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8085,
         workers: 1
      );
      $HTTP_Server_CLI->on(
         Events::RequestReceived,
         function ($Request, Response $Response): Response {
            if ($Request->URI === '/h2-s4-large') {
               return $Response->upload('statics/h2-s4-large.bin', close: false);
            }

            return $Response->send("method={$Request->method};uri={$Request->URI};protocol={$Request->protocol};body={$Request->input}");
         }
      );
      // ! These specs exercise the real dispatch pipeline, not the
      //   index-based test harness — swap `Encoder_Testing` (installed by
      //   Modes::Test) for the canonical `Encoder_` before the worker forks.
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
         if (is_file($largeFile)) {
            unlink($largeFile);
         }
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-preface_settings',
      '1.2-malformed',
      '1.3-protocol_errors',
      '2.1-requests',
      '2.2-multiplex',
      '2.3-validation',
      '3.1-curl',
      '4.1-hardening',
      '4.2-compliance',
      '4.3-file_streaming',
      '4.4-feeding_contract',
      '5.1-throughput',
      '5.2-h2spec'
   ]
);

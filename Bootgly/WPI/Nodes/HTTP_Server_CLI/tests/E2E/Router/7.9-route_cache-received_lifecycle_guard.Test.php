<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// M1 Received parity regression for Encoder_Testing. Request 1 primes the
// route cache and installs a one-shot Received listener. Request 2 must flush
// that wire before the listener changes the persistent preset, execute the
// handler, and emit the current value. A distinct cleanup route makes teardown
// independent of that outcome; requests 4 and 5 prove clean cache recovery.

$handlerRuns = 0;
$OriginalEmitter = null;

return new Test(
   Separator: new Separator(left: 'Route response cache lifecycle'),
   description: 'It should transition route cache fail-closed around Received listeners',

   requests: [
      static fn (): string => "GET /cached/security-m1-received HTTP/1.1\r\n"
         . "Host: localhost\r\nX-M1-Preset: primer\r\n\r\n",
      static fn (): string => "GET /cached/security-m1-received HTTP/1.1\r\n"
         . "Host: localhost\r\nX-M1-Preset: current\r\n\r\n",
      static fn (): string => "GET /cached/security-m1-received-cleanup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
      static fn (): string => "GET /cached/security-m1-received HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
      static fn (): string => "GET /cached/security-m1-received HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router
   ) use (&$handlerRuns, &$OriginalEmitter): Generator {
      yield $Router->route(
         '/cached/security-m1-received-cleanup',
         static function (
            Request $Request,
            Response $Response
         ) use (&$OriginalEmitter): Response {
            if ($OriginalEmitter instanceof Emitter) {
               Emitter::$Instance = $OriginalEmitter;
            }
            $Response->Header->preset('X-M1-Testing-Received', null);

            return $Response(body: 'M1-TESTING-RECEIVED:CLEANUP');
         },
         GET
      );

      yield $Router->route('/cached/security-m1-received', static function (
         Request $Request,
         Response $Response
      ) use (&$handlerRuns, &$OriginalEmitter): Response {
         $handlerRuns++;

         if ($handlerRuns === 1) {
            $Response->Header->preset(
               'X-M1-Testing-Received',
               $Request->headers['x-m1-preset'] ?? 'missing'
            );

            $OriginalEmitter = Emitter::$Instance;
            $ReceivedEmitter = new Emitter;
            $ReceivedEmitter->listen(
               RequestEvents::Received,
               static function (Emission $Emission) use ($OriginalEmitter): void {
                  // ! One-shot restoration keeps the worker's original event
                  //   bus intact even when a future regression replays wire.
                  Emitter::$Instance = $OriginalEmitter;

                  /** @var Request $Request */
                  [$Request] = $Emission->payload;
                  if ($Request->URI !== '/cached/security-m1-received') {
                     return;
                  }

                  Server::$Response->Header->preset(
                     'X-M1-Testing-Received',
                     $Request->headers['x-m1-preset'] ?? 'missing'
                  );
               }
            );
            Emitter::$Instance = $ReceivedEmitter;
         }

         return $Response(body: 'M1-TESTING-RECEIVED:handler=' . $handlerRuns);
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      [$primer, $current, $cleanup, $recovery, $replay] = $responses;

      $Header = static function (string $wire): null|string {
         if (
            preg_match(
               '/^X-M1-Testing-Received:\s*([^\r\n]+)\r?$/mi',
               $wire,
               $matches
            ) !== 1
         ) {
            return null;
         }

         return $matches[1];
      };

      $evidence = [
         'primer_header' => $Header($primer),
         'current_header' => $Header($current),
         'cleanup_header' => $Header($cleanup),
         'recovery_header' => $Header($recovery),
         'replay_header' => $Header($replay),
         'primer_handler' => str_contains(
            $primer,
            'M1-TESTING-RECEIVED:handler=1'
         ),
         'current_handler' => str_contains(
            $current,
            'M1-TESTING-RECEIVED:handler=2'
         ),
         'cleanup_handler' => str_contains(
            $cleanup,
            'M1-TESTING-RECEIVED:CLEANUP'
         ),
         'recovery_handler' => str_contains(
            $recovery,
            'M1-TESTING-RECEIVED:handler=3'
         ),
         'replay_handler' => str_contains(
            $replay,
            'M1-TESTING-RECEIVED:handler=3'
         ),
      ];

      if (
         $evidence['primer_header'] !== 'primer'
         || $evidence['current_header'] !== 'current'
         || $evidence['cleanup_header'] !== null
         || $evidence['recovery_header'] !== null
         || $evidence['replay_header'] !== null
         || $evidence['primer_handler'] !== true
         || $evidence['current_handler'] !== true
         || $evidence['cleanup_handler'] !== true
         || $evidence['recovery_handler'] !== true
         || $evidence['replay_handler'] !== true
      ) {
         Vars::$labels = ['M1 Encoder_Testing Received lifecycle evidence'];
         dump(json_encode($responses), json_encode($evidence));

         return 'M1 reproduced: Received output entered or consumed shared route-cache wire.';
      }

      return true;
   }
);

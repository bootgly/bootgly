<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
/**
 * TLS-handshake regression — Connection cleanup must tolerate an instance
 * whose constructor did not reach the handshake-timer initialization.
 *
 * Security tests use deliberately minimal Connection doubles to exercise
 * lower protocol layers. The production destructor also documents support
 * for partially constructed instances, so every cleanup field it reads must
 * have a neutral default independently of constructor completion.
 */

$probe = [
   'control' => false,
   'handshaking_default' => false,
   'partial' => false,
   'error' => '',
];

return new Specification(
   description: 'Connection destruction must tolerate an uninitialized handshake timer',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $Reflection = new ReflectionClass(Connection::class);

      try {
         /** @var Connection $Control */
         $Control = $Reflection->newInstanceWithoutConstructor();
         $Control->timers = [];
         $Control->handshakeTimer = 0;
         $Control->__destruct();
         $probe['control'] = true;
         unset($Control);

         /** @var Connection $Connection */
         $Connection = $Reflection->newInstanceWithoutConstructor();
         $Connection->timers = [];

         try {
            $Connection->__destruct();
            $probe['handshaking_default'] = $Connection->handshaking === false;
            $probe['partial'] = true;
         }
         catch (Throwable $Throwable) {
            $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();

            // @ Make the automatic destructor safe after preserving the
            //   vulnerable result from the explicit cleanup call.
            $Connection->handshakeTimer = 0;
         }

         unset($Connection);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /partial-connection-destructor HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/partial-connection-destructor', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'PARTIAL-CONNECTION-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'PARTIAL-CONNECTION-HARNESS-OK')) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Partial-connection destructor harness did not reach its control route.';
      }

      if ($probe['control'] !== true) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Initialized handshake-timer control did not complete cleanup.';
      }

      if ($probe['partial'] !== true) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'TLS cleanup regression: Connection::__destruct() accessed an uninitialized handshakeTimer. '
            . $probe['error'];
      }

      if ($probe['handshaking_default'] !== true) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'TLS cleanup regression: a partial Connection did not default to non-handshaking state.';
      }

      return true;
   }
);

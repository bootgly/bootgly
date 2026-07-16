<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — an expired body wait must reject, never redispatch late bytes.
 *
 * Reflection backdates only the decoder's private body-start timestamp so the
 * test does not sleep for 60 seconds. The production decode path and package
 * contract remain unchanged.
 */

$probe = [
   'error' => '',
   'state' => '',
   'rejection' => '',
   'fallbackCalled' => false,
   'consumed' => -1,
];

return new Specification(
   description: 'Expired Content-Length body waits must reject without decoding late body bytes',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = Bootgly\WPI;
      $OldRequest = $WPI->Request;
      $OldDecoder = Server::$Decoder;

      try {
         $Request = new Request;
         $Request->Body->length = 46;
         $Request->Body->downloaded = 0;
         $Request->Body->waiting = true;
         $WPI->Request = $Request;

         $Fallback = new class extends Decoders {
            public bool $called = false;

            public function decode (Packages $Package, string $buffer, int $size): States
            {
               $this->called = true;
               $Package->consumed = $size;
               return States::Complete;
            }
         };
         Server::$Decoder = $Fallback;

         $Package = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         $Waiting = new Decoder_Waiting;
         $Waiting->init();

         $Reflection = new ReflectionClass($Waiting);
         $Decoded = $Reflection->getProperty('decoded');
         $Decoded->setValue($Waiting, time() - 61);

         $late = "GET /c1-timeout-smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $State = $Waiting->decode($Package, $late, strlen($late));

         $probe['state'] = $State->name;
         $probe['rejection'] = $Package->rejection;
         $probe['fallbackCalled'] = $Fallback->called;
         $probe['consumed'] = $Package->consumed;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $WPI->Request = $OldRequest;
         Server::$Decoder = $OldDecoder;
      }

      return "GET /c1-timeout-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/c1-timeout-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Harness request did not reach /c1-timeout-harness.';
      }

      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if ($probe['state'] !== States::Rejected->name) {
         return 'Expired body wait did not return States::Rejected.';
      }

      if (! str_contains($probe['rejection'], '408 Request Timeout')) {
         return 'Expired body wait did not emit HTTP 408.';
      }

      if ($probe['fallbackCalled']) {
         return 'Expired body bytes reached the default HTTP decoder.';
      }

      if ($probe['consumed'] !== 0) {
         return 'Expired body wait reported late bytes as consumed.';
      }

      return true;
   }
);

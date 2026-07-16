<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — an expired chunked body must reject late bytes.
 *
 * Reflection backdates only the decoder's private absolute deadline anchor,
 * avoiding a 30-second sleep. Request-shaped bytes arriving after that point
 * must receive 408 and must never reach the default HTTP decoder.
 */

$probe = [
   'error' => '',
   'state' => '',
   'rejection' => '',
   'fallbackCalled' => false,
   'consumed' => -1,
];

return new Specification(
   description: 'Expired chunked bodies must reject without decoding late bytes',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = Bootgly\WPI;
      $OldRequest = $WPI->Request;
      $OldDecoder = Server::$Decoder;

      try {
         $Request = new Request;
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

         $Decoder = new Decoder_Chunked;
         $Decoder->init();
         $Package->Decoder = $Decoder;

         $Reflection = new ReflectionClass($Decoder);
         $Decoded = $Reflection->getProperty('decoded');
         $Decoded->setValue($Decoder, time() - 31);

         $late = "GET /h2-timeout-smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $State = $Decoder->decode($Package, $late, strlen($late));

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

      return "GET /h2-timeout-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h2-timeout-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Harness request did not reach /h2-timeout-harness.';
      }

      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if ($probe['state'] !== States::Rejected->name) {
         return 'Expired chunked body did not return States::Rejected.';
      }

      if (! str_contains($probe['rejection'], '408 Request Timeout')) {
         return 'Expired chunked body did not emit HTTP 408.';
      }

      if ($probe['fallbackCalled']) {
         return 'Expired chunked body bytes reached the default HTTP decoder.';
      }

      if ($probe['consumed'] !== 0) {
         return 'Expired chunked body reported late bytes as consumed.';
      }

      return true;
   }
);

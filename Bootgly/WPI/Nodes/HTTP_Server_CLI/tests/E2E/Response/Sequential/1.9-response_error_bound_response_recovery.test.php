<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Error recovery'),

   requests: [
      function () {
         return "GET /boom HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /probe HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! The regression: the Catcher's fresh Response was written through the
      //   `&Server::$Response` alias, replacing the worker's bound Response —
      //   its loaded resources (and any persistent resource state) were lost
      //   for every later request. The persistent View resource carries the
      //   marker across requests to prove the bound Response survived.
      yield $Router->route('/boom', function ($Request, $Response) {
         $Response->View->layout = 'recovery-marker';

         throw new RuntimeException('boom');
      }, GET);

      yield $Router->route('/probe', function ($Request, $Response) {
         $kept = $Response->View->layout === 'recovery-marker';
         $Response->View->layout = '';

         return $Response(body: $kept ? 'kept' : 'lost');
      }, GET);
   },

   test: function (array $responses) {
      // @ Assert Response 1 — Test env byte-exact legacy 500
      $expectedBoom = "HTTP/1.1 500 Internal Server Error\r\n"
         . "Server: Bootgly\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "Content-Length: 1\r\n"
         . "\r\n"
         . ' ';

      if ($responses[0] !== $expectedBoom) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedBoom));
         return 'Thrown route did not produce the Test-env 500';
      }

      // @ Assert Response 2 — the bound Response (and its state) survived
      $expectedProbe = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 4\r
      \r
      kept
      HTML_RAW;

      if ($responses[1] !== $expectedProbe) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expectedProbe));
         return 'The worker-bound Response was replaced after an error';
      }

      return true;
   }
);

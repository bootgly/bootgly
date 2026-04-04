<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   requests: [
      function () {
         return "GET /deferred HTTP/1.0\r\n\r\n";
      },
      function () {
         return "GET /deferred HTTP/1.0\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/deferred', function (Request $Request, Response $Response) {
         return $Response->defer(function () use ($Response) {
            // @ Simulate awaiting async I/O
            $Response->wait();

            // @ Complete the response after resuming
            $Response(body: 'Deferred Response!');
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 18\r
      \r
      Deferred Response!
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expected) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expected));
         return 'First deferred response not matched';
      }

      // @ Assert Response 2
      if ($responses[1] !== $expected) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expected));
         return 'Second deferred response not matched (state corruption)';
      }

      return true;
   }
);

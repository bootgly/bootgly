<?php

use function implode;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Duplicate route params reset between requests (no accumulation)',

   requests: [
      function () {
         return "GET /pair/1/2 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /pair/3/4 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! The regression: the regex path appended duplicate-param values onto
      //   whatever the Params object still held from the previous request —
      //   the second request answered [1,2,3,4] instead of [3,4].
      yield $Router->route('/pair/:v/:v', function ($Request, $Response) {
         return $Response(body: implode(',', (array) $this->Params->v));
      }, GET);
   },

   test: function (array $responses) {
      $expectedFirst = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 3\r
      \r
      1,2
      HTML_RAW;

      $expectedSecond = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 3\r
      \r
      3,4
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expectedFirst) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedFirst));
         return 'First duplicate-param request not matched';
      }

      // @ Assert Response 2 — fresh values only, nothing accumulated
      if ($responses[1] !== $expectedSecond) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expectedSecond));
         return 'Duplicate params accumulated across requests';
      }

      return true;
   }
);

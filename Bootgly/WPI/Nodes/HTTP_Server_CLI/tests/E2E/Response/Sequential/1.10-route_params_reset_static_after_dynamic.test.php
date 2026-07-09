<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Route params reset'),

   requests: [
      function () {
         return "GET /page/about HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! The regression: static matches dispatched straight from the cache
      //   without resetting the Route — params from a previous dynamic match
      //   leaked into the static request (a '/' page rendering the previous
      //   '/page/about' view, for example).
      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'home:' . ($this->Params->page ?? 'none'));
      }, GET);

      yield $Router->route('/page/:page<alpha>', function ($Request, $Response) {
         return $Response(body: "page:{$this->Params->page}");
      }, GET);
   },

   test: function (array $responses) {
      $expectedPage = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 10\r
      \r
      page:about
      HTML_RAW;

      $expectedHome = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 9\r
      \r
      home:none
      HTML_RAW;

      // @ Assert Response 1 — dynamic match fills the param
      if ($responses[0] !== $expectedPage) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedPage));
         return 'Dynamic param request not matched';
      }

      // @ Assert Response 2 — static match sees NO stale params
      if ($responses[1] !== $expectedHome) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expectedHome));
         return 'Stale route params leaked into the static match';
      }

      return true;
   }
);

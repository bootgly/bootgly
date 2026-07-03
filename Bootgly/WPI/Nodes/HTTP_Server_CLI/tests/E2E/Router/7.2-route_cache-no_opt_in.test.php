<?php

use function hrtime;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Control: a route WITHOUT `cache:` must run its handler on every request —
// the hrtime() body must differ between requests (no accidental caching).

return new Specification(
   description: 'It should re-run the handler on every request when the route did not opt in',

   requests: [
      function () {
         return "GET /cached/control HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/control HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/cached/control', function ($Request, $Response) {
         return $Response(body: 'at=' . hrtime(true));
      }, GET);
   },

   test: function (array $responses) {
      [$r1, $r2] = $responses;

      if (! str_contains($r1, 'at=') || ! str_contains($r2, 'at=')) {
         Vars::$labels = ['Response 1:', 'Response 2:'];
         dump(json_encode($r1), json_encode($r2));
         return 'Handler body missing from responses';
      }

      if ($r1 === $r2) {
         Vars::$labels = ['Response 1:', 'Response 2:'];
         dump(json_encode($r1), json_encode($r2));
         return 'Non-opted route served byte-identical responses — accidental caching';
      }

      return true;
   }
);

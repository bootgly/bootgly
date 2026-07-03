<?php

use function hrtime;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Route response cache HIT: a route opted in via `cache:` runs its handler
// once — repeated identical GETs are served from the stored wire bytes.
// The handler body embeds hrtime(): re-execution would change the body, so
// byte-identical responses prove the bytes came from the cache.

return new Specification(
   Separator: new Separator(left: 'Route response cache'),
   description: 'It should serve repeated GETs from the route cache without re-running the handler',

   requests: [
      function () {
         return "GET /cached/hit HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/hit HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/hit HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/cached/hit', function ($Request, $Response) {
         return $Response(body: 'at=' . hrtime(true));
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3] = $responses;

      foreach ($responses as $index => $response) {
         if (! str_contains($response, 'HTTP/1.1 200') || ! str_contains($response, 'at=')) {
            Vars::$labels = ['Response ' . ($index + 1) . ':'];
            dump(json_encode($response));
            return 'Cached route did not answer 200 with the handler body';
         }
      }

      if ($r2 !== $r1 || $r3 !== $r1) {
         Vars::$labels = ['Response 1:', 'Response 2:', 'Response 3:'];
         dump(json_encode($r1), json_encode($r2), json_encode($r3));
         return 'Repeated GETs re-ran the handler — expected byte-identical wire served from the route cache';
      }

      return true;
   }
);

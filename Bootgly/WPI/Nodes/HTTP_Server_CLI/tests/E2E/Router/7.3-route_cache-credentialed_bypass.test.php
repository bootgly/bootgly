<?php

use function hrtime;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Security: requests carrying credentials (Cookie / Authorization) must never
// read NOR seed the route cache. Request order: credentialed (must not seed),
// anonymous (seeds), anonymous (hits — byte-identical), credentialed (must
// not read the anonymous entry — fresh body).

return new Specification(
   description: 'It should bypass the route cache for credentialed requests (no read, no seed)',

   requests: [
      function () {
         return "GET /cached/auth HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc\r\n\r\n";
      },
      function () {
         return "GET /cached/auth HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/auth HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/auth HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer x\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/cached/auth', function ($Request, $Response) {
         return $Response(body: 'at=' . hrtime(true));
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3, $r4] = $responses;

      foreach ($responses as $index => $response) {
         if (! str_contains($response, 'at=')) {
            Vars::$labels = ['Response ' . ($index + 1) . ':'];
            dump(json_encode($response));
            return 'Handler body missing from response ' . ($index + 1);
         }
      }

      if ($r2 === $r1) {
         Vars::$labels = ['Response 1:', 'Response 2:'];
         dump(json_encode($r1), json_encode($r2));
         return 'Credentialed request seeded the cache — anonymous request got its bytes';
      }

      if ($r3 !== $r2) {
         Vars::$labels = ['Response 2:', 'Response 3:'];
         dump(json_encode($r2), json_encode($r3));
         return 'Anonymous repeat must hit the cache seeded by the anonymous request';
      }

      if ($r4 === $r2) {
         Vars::$labels = ['Response 2:', 'Response 4:'];
         dump(json_encode($r2), json_encode($r4));
         return 'Credentialed request read the route cache — expected a fresh handler run';
      }

      return true;
   }
);

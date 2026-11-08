<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// A configured TrustedProxy `header` (CF-Connecting-IP) sits outside the
// built-in forwarded selectors (X-Forwarded-For/-Proto, X-Real-IP).
//
// 1. The protection in force: a route carrying the middleware is never
//    cached, so its rewritten address is resolved on every request.
// 2. Defense in depth: once an instance reads that field, the route cache
//    treats it like the built-in selectors — a request carrying it neither
//    reads an entry primed without it nor seeds one another visitor could
//    read — so a future cache-eligible middleware route cannot replay one
//    visitor's representation to another.

$guardedRuns = 0;
$plainRuns = 0;

return new Test(
   Separator: new Separator(left: 'Route response cache forwarding header'),
   description: 'It should keep requests with a configured forwarding header out of the route cache',

   requests: [
      // # 1. A TrustedProxy route never caches
      fn () => "GET /cached/forwarding-guarded HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/forwarding-guarded HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // # 2. Prime a cached route, then send visitors with the configured field
      fn () => "GET /cached/forwarding-plain HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/forwarding-plain HTTP/1.1\r\nHost: localhost\r\nCF-Connecting-IP: 203.0.113.1\r\n\r\n",
      fn () => "GET /cached/forwarding-plain HTTP/1.1\r\nHost: localhost\r\nCF-Connecting-IP: 203.0.113.2\r\n\r\n",
      fn () => "GET /cached/forwarding-plain HTTP/1.1\r\nHost: localhost\r\nCF-Connecting-IP: 203.0.113.1\r\n\r\n",
      // # Positive control — the ordinary entry still hits
      fn () => "GET /cached/forwarding-plain HTTP/1.1\r\nHost: localhost\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) use (&$guardedRuns, &$plainRuns) {
      // ! Registers `cf-connecting-ip` with the route cache before any route runs
      $TrustedProxy = new TrustedProxy(
         proxies: ['127.0.0.1', '::1'],
         strategy: Strategies::Header,
         header: 'CF-Connecting-IP'
      );

      yield $Router->route('/cached/forwarding-guarded', function ($Request, $Response) use (&$guardedRuns) {
         $guardedRuns++;

         return $Response(body: "address={$Request->address};run={$guardedRuns}");
      }, GET, middlewares: [$TrustedProxy], cache: ['TTL' => 60]);

      yield $Router->route('/cached/forwarding-plain', function ($Request, $Response) use (&$plainRuns) {
         $plainRuns++;

         return $Response(body: "run={$plainRuns}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$guarded1, $guarded2, $primed, $visitor1, $visitor2, $visitor1Again, $control] = $responses;

      if (
         ! preg_match('/address=(127\.0\.0\.1|::1);run=1$/', $guarded1)
         || ! preg_match('/address=(127\.0\.0\.1|::1);run=2$/', $guarded2)
      ) {
         Vars::$labels = ['Guarded 1:', 'Guarded 2:'];
         dump(json_encode($guarded1), json_encode($guarded2));
         return 'A route carrying TrustedProxy was served from the route cache';
      }

      if (
         ! str_ends_with($primed, 'run=1')
         || ! str_ends_with($visitor1, 'run=2')
         || ! str_ends_with($visitor2, 'run=3')
         || ! str_ends_with($visitor1Again, 'run=4')
         || ! str_ends_with($control, 'run=1')
      ) {
         Vars::$labels = ['Primed:', 'Visitor 1:', 'Visitor 2:', 'Visitor 1 again:', 'Control:'];
         dump(
            json_encode($primed),
            json_encode($visitor1),
            json_encode($visitor2),
            json_encode($visitor1Again),
            json_encode($control)
         );
         return 'Route cache replayed a representation across a configured forwarding header';
      }

      return true;
   }
);

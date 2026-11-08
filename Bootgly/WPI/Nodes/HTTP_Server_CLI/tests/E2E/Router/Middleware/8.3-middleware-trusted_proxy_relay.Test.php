<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// A CDN (173.245.48.0/20) in front of an edge that overwrites XFF with the
// hop it saw: the left-most entry is the CDN node, so its visitor header
// (`relay`) names the client. The relay is never read when no forwarded data
// picked an address — the socket peer itself is trusted, and a client that
// reaches the edge directly writes `CF-Connecting-IP` itself.

return new Test(
   description: 'It should hand a trusted front proxy over to its relay header, never on the socket-peer fallback',

   requests: [
      fn () => "GET /tp/leftmost HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 173.245.48.5\r\nCF-Connecting-IP: 203.0.113.9\r\n\r\n",
      fn () => "GET /tp/leftmost HTTP/1.1\r\nHost: localhost\r\n"
         . "CF-Connecting-IP: 203.0.113.66\r\n\r\n",
      fn () => "GET /tp/rightmost HTTP/1.1\r\nHost: localhost\r\n"
         . "CF-Connecting-IP: 203.0.113.66\r\n\r\n",
      fn () => "GET /tp/rightmost HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 173.245.48.5\r\nCF-Connecting-IP: 2001:db8::9\r\n\r\n",
      fn () => "GET /tp/rightmost HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 198.51.100.7, 173.245.48.5\r\nCF-Connecting-IP: 203.0.113.66\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      $Describe = static function (Request $Request, Response $Response): Response {
         return $Response(body: "address={$Request->address}");
      };
      $proxies = ['127.0.0.1', '::1', '173.245.48.0/20'];

      yield $Router->route('/tp/leftmost', $Describe, GET, middlewares: [
         new TrustedProxy(proxies: $proxies, strategy: Strategies::Leftmost, relay: 'CF-Connecting-IP'),
      ]);
      yield $Router->route('/tp/rightmost', $Describe, GET, middlewares: [
         new TrustedProxy(proxies: $proxies, relay: 'CF-Connecting-IP'),
      ]);
   },

   test: function (array $responses) {
      [$relayed, $leftFallback, $rightFallback, $relayed6, $direct] = $responses;

      if (
         ! str_contains($relayed, 'address=203.0.113.9')
         || ! preg_match('/address=(127\.0\.0\.1|::1)$/', $leftFallback)
         || ! preg_match('/address=(127\.0\.0\.1|::1)$/', $rightFallback)
         || ! str_contains($relayed6, 'address=2001:db8::9')
         || ! str_contains($direct, 'address=198.51.100.7')
      ) {
         Vars::$labels = ['Relayed:', 'Leftmost fallback:', 'Rightmost fallback:', 'Relayed IPv6:', 'Direct:'];
         dump(
            json_encode($relayed),
            json_encode($leftFallback),
            json_encode($rightFallback),
            json_encode($relayed6),
            json_encode($direct)
         );
         return 'The relay resolved wrongly or was read on the socket-peer fallback';
      }

      return true;
   }
);

<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// An X-Real-IP-only edge (`proxy_set_header X-Real-IP $remote_addr;`) passes
// a client-written X-Forwarded-For through. The default strategy reads that
// forged XFF (control); `Strategies::Header` reads only the edge's header.

return new Test(
   description: 'It should resolve the client from one named header with Strategies::Header',

   requests: [
      fn () => "GET /tp/default HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 10.0.0.1\r\nX-Real-IP: 203.0.113.77\r\n\r\n",
      fn () => "GET /tp/header HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 10.0.0.1\r\nX-Real-IP: 203.0.113.77\r\n\r\n",
      fn () => "GET /tp/header HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Real-IP: 203.0.113.77\r\nX-Real-IP: 10.0.0.1\r\n\r\n",
      fn () => "GET /tp/header HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Real-IP: not-an-ip\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      $Describe = static function (Request $Request, Response $Response): Response {
         return $Response(body: "address={$Request->address}");
      };

      yield $Router->route('/tp/default', $Describe, GET, middlewares: [
         new TrustedProxy(proxies: ['127.0.0.1', '::1']),
      ]);
      yield $Router->route('/tp/header', $Describe, GET, middlewares: [
         new TrustedProxy(proxies: ['127.0.0.1', '::1'], strategy: Strategies::Header, header: 'X-Real-IP'),
      ]);
   },

   test: function (array $responses) {
      [$default, $header, $repeated, $malformed] = $responses;

      if (
         ! str_contains($default, 'address=10.0.0.1')
         || ! str_contains($header, 'address=203.0.113.77')
         || ! preg_match('/address=(127\.0\.0\.1|::1)$/', $repeated)
         || ! preg_match('/address=(127\.0\.0\.1|::1)$/', $malformed)
      ) {
         Vars::$labels = ['Default:', 'Header:', 'Repeated:', 'Malformed:'];
         dump(json_encode($default), json_encode($header), json_encode($repeated), json_encode($malformed));
         return 'Strategies::Header did not resolve only a single valid edge-written header';
      }

      return true;
   }
);

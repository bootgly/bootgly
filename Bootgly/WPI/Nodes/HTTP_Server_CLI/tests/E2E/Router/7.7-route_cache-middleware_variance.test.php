<?php

use function gzdecode;
use function str_contains;
use function str_repeat;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Compression;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// M8 integration regression: middleware-dependent representations must never
// be replayed by the route cache before those middlewares execute. The first
// request intentionally primes each route with its base representation.

$controlRuns = 0;
$compressionRuns = 0;
$corsRuns = 0;
$protoRuns = 0;

return new Specification(
   Separator: new Separator(left: 'Route response cache variance'),
   description: 'It should preserve Compression, CORS and trusted-proxy variance before route-cache hits',

   requests: [
      // # Cache-positive control
      fn () => "GET /cached/m8-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/m8-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // # Compression: identity primes first, then gzip, then identity again
      fn () => "GET /cached/m8-compression HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/m8-compression HTTP/1.1\r\nHost: localhost\r\nAccept-Encoding: gzip\r\n\r\n",
      fn () => "GET /cached/m8-compression HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // # CORS: no-Origin primes first, then allowed/disallowed/no-Origin
      fn () => "GET /cached/m8-cors HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/m8-cors HTTP/1.1\r\nHost: localhost\r\nOrigin: https://allowed.example\r\n\r\n",
      fn () => "GET /cached/m8-cors HTTP/1.1\r\nHost: localhost\r\nOrigin: https://denied.example\r\n\r\n",
      fn () => "GET /cached/m8-cors HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // # TrustedProxy: prime the ordinary namespace, then prove forwarded
      //   requests neither read it nor seed their own reusable entry.
      fn () => "GET /cached/m8-proto HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn () => "GET /cached/m8-proto HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: https\r\n\r\n",
      fn () => "GET /cached/m8-proto HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: https\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) use (
      &$controlRuns,
      &$compressionRuns,
      &$corsRuns,
      &$protoRuns
   ) {
      yield $Router->route('/cached/m8-control', function ($Request, $Response) use (&$controlRuns) {
         $controlRuns++;

         return $Response(body: "control-run={$controlRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/cached/m8-compression', function ($Request, $Response) use (&$compressionRuns) {
         $compressionRuns++;

         return $Response(body: "compression-run={$compressionRuns}:" . str_repeat('x', 256));
      }, GET, middlewares: [new Compression(minSize: 64)], cache: ['TTL' => 60]);

      yield $Router->route('/cached/m8-cors', function ($Request, $Response) use (&$corsRuns) {
         $corsRuns++;

         return $Response(body: "cors-run={$corsRuns}");
      }, GET, middlewares: [new CORS(origins: ['https://allowed.example'])], cache: ['TTL' => 60]);

      yield $Router->route('/cached/m8-proto', function ($Request, $Response) use (&$protoRuns) {
         $protoRuns++;

         return $Response(body: "proto={$Request->scheme};run={$protoRuns}");
      }, GET, middlewares: [new TrustedProxy(proxies: ['127.0.0.1'])], cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [
         $control1, $control2,
         $identity1, $gzip, $identity2,
         $corsBase1, $corsAllowed, $corsDenied, $corsBase2,
         $protoHTTP, $protoHTTPS1, $protoHTTPS2,
      ] = $responses;

      if (
         ! str_contains($control1, 'control-run=1')
         || ! str_contains($control2, 'control-run=1')
      ) {
         Vars::$labels = ['Control 1:', 'Control 2:'];
         dump(json_encode($control1), json_encode($control2));
         return 'The cookie-free, variance-free route-cache positive control did not hit';
      }

      $bodyOffset = strpos($gzip, "\r\n\r\n");
      $decoded = $bodyOffset === false ? false : gzdecode(substr($gzip, $bodyOffset + 4));
      if (
         ! str_contains($identity1, 'compression-run=1:')
         || ! str_contains($identity2, 'compression-run=3:')
         || ! str_contains($gzip, "Content-Encoding: gzip\r\n")
         || $decoded === false
         || ! str_contains($decoded, 'compression-run=2:')
      ) {
         Vars::$labels = ['Identity 1:', 'Gzip:', 'Identity 2:', 'Decoded gzip:'];
         dump(json_encode($identity1), json_encode($gzip), json_encode($identity2), json_encode($decoded));
         return 'Route cache replayed a representation across Accept-Encoding variants';
      }
      foreach ([$identity1, $gzip, $identity2] as $response) {
         if (! str_contains($response, "Vary: Accept-Encoding\r\n")) {
            return 'Compression representation omitted Vary: Accept-Encoding';
         }
      }

      if (
         ! str_contains($corsBase1, 'cors-run=1')
         || ! str_contains($corsAllowed, 'cors-run=2')
         || ! str_contains($corsAllowed, "Access-Control-Allow-Origin: https://allowed.example\r\n")
         || ! str_contains($corsDenied, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($corsDenied, 'Origin not allowed')
         || ! str_contains($corsBase2, 'cors-run=3')
      ) {
         Vars::$labels = ['CORS base 1:', 'CORS allowed:', 'CORS denied:', 'CORS base 2:'];
         dump(
            json_encode($corsBase1),
            json_encode($corsAllowed),
            json_encode($corsDenied),
            json_encode($corsBase2)
         );
         return 'Route cache replayed the no-Origin representation before CORS policy';
      }
      foreach ([$corsBase1, $corsAllowed, $corsDenied, $corsBase2] as $response) {
         if (! str_contains($response, "Vary: Origin\r\n")) {
            return 'Non-wildcard CORS representation omitted Vary: Origin';
         }
      }

      if (
         ! str_contains($protoHTTP, 'proto=http;run=1')
         || ! str_contains($protoHTTPS1, 'proto=https;run=2')
         || ! str_contains($protoHTTPS2, 'proto=https;run=3')
      ) {
         Vars::$labels = ['Forwarded HTTP:', 'Forwarded HTTPS 1:', 'Forwarded HTTPS 2:'];
         dump(json_encode($protoHTTP), json_encode($protoHTTPS1), json_encode($protoHTTPS2));
         return 'Route cache crossed or stored a forwarded scheme selector before TrustedProxy';
      }

      return true;
   }
);

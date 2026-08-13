<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L3 (HTTP_Server_CLI audit 2026-08-02) — the CSRF same-origin
 * check must accept a bracketed IPv6 literal, with or without an explicit
 * port, when Host and Origin carry the same authority.
 *
 * The DNS same-origin leg proves a valid token reaches the protected handler.
 * The DNS cross-origin leg proves origin enforcement is active and rejects
 * before the handler. On vulnerable code, both IPv6 legs follow that rejection
 * path because `explode(':', $host, 2)` truncates the Host to `[2001` while
 * `parse_url()` retains `[2001:db8::1]` as the Origin host.
 */
$knownToken = str_repeat('0123456789abcdef', 4);

$Request = static function (
   string $leg,
   string $host,
   string $origin,
) use ($knownToken): Closure {
   return static function () use ($leg, $host, $origin, $knownToken): string {
      return "POST /l3/csrf HTTP/1.1\r\n"
         . "Host: {$host}\r\n"
         . "Origin: {$origin}\r\n"
         . "X-CSRF-Token: {$knownToken}\r\n"
         . "X-L3-Leg: {$leg}\r\n"
         . "Content-Length: 0\r\n"
         . "Connection: close\r\n\r\n";
   };
};

return new Test(
   description: 'CSRF same-origin validation must accept bracketed IPv6 literal authorities',
   Separator: new Separator(line: true),

   requests: [
      $Request(
         'dns-same-origin',
         'control.example.test:8081',
         'http://control.example.test:8081',
      ),
      $Request(
         'dns-cross-origin',
         'control.example.test:8081',
         'http://attacker.example.test:8081',
      ),
      $Request(
         'ipv6-without-port',
         '[2001:db8::1]',
         'http://[2001:db8::1]',
      ),
      $Request(
         'ipv6-with-port',
         '[2001:db8::1]:8081',
         'http://[2001:db8::1]:8081',
      ),
   ],

   middlewares: [
      new class($knownToken) implements Middleware {
         public function __construct (private string $knownToken)
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $Request->Session->set('_csrf_token', $this->knownToken);

            return $next($Request, $Response);
         }
      },
      new CSRF(checkOrigin: true),
   ],

   response: static function (Request $Request, Response $Response): Response {
      $leg = $Request->Header->get('X-L3-Leg') ?? 'missing';

      return $Response(body: "L3-PROTECTED-HANDLER:{$leg}");
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 4) {
         return 'L3 fixture failed: expected four live CSRF responses, got '
            . count($responses) . '.';
      }

      [$sameOrigin, $crossOrigin, $IPv6WithoutPort, $IPv6WithPort] = $responses;

      if (
         str_contains($sameOrigin, 'HTTP/1.1 200 OK') === false
         || str_contains($sameOrigin, 'L3-PROTECTED-HANDLER:dns-same-origin') === false
      ) {
         Vars::$labels = ['L3 DNS same-origin positive control'];
         dump(json_encode($sameOrigin));

         return 'L3 control failed: a valid DNS same-origin request with a valid token '
            . 'did not reach the protected handler.';
      }

      if (
         str_contains($crossOrigin, 'HTTP/1.1 403 Forbidden') === false
         || str_contains($crossOrigin, 'Invalid CSRF origin') === false
         || str_contains($crossOrigin, 'L3-PROTECTED-HANDLER:') === true
      ) {
         Vars::$labels = ['L3 DNS cross-origin negative control'];
         dump(json_encode($crossOrigin));

         return 'L3 control failed: the cross-origin request did not prove that CSRF '
            . 'origin enforcement was active before handler dispatch.';
      }

      $IPv6Responses = [
         'without-port' => $IPv6WithoutPort,
         'with-port' => $IPv6WithPort,
      ];
      $rejected = [];

      foreach ($IPv6Responses as $leg => $response) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "L3-PROTECTED-HANDLER:ipv6-{$leg}")
         ) {
            continue;
         }

         if (
            str_contains($response, 'HTTP/1.1 403 Forbidden')
            && str_contains($response, 'Invalid CSRF origin')
            && str_contains($response, 'L3-PROTECTED-HANDLER:') === false
         ) {
            $rejected[] = $leg;
            continue;
         }

         Vars::$labels = ["L3 unexpected IPv6 {$leg} response"];
         dump(json_encode($response));

         return "L3 fixture failed: the IPv6 {$leg} leg produced neither the "
            . 'vulnerable origin rejection nor the secure handler response.';
      }

      if ($rejected !== []) {
         Vars::$labels = ['L3 bracketed IPv6 same-origin rejection evidence'];
         dump(json_encode($IPv6Responses));

         return 'CONFIRMED L3: CSRF origin validation rejected valid same-origin '
            . 'bracketed IPv6 requests with a valid token: '
            . implode(', ', $rejected) . '.';
      }

      return true;
   },
);

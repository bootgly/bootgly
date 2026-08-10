<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L1 — each TrustedProxy pass must authenticate the immutable
 * transport peer, not Request::$address after an earlier pass changed it.
 *
 * All three requests carry the same forwarding chain. The outer control
 * proves that a proxy which trusts the real loopback peer resolves only the
 * right-most untrusted hop. The inner control proves that a proxy which trusts
 * only that intermediate hop rejects the real loopback peer. Stacking those
 * two production middlewares must not let the second pass treat the mutable
 * address produced by the first pass as its authenticated transport peer.
 */
$forwarded = '203.0.113.66, 198.51.100.10';
$attackerAddress = '203.0.113.66';
$trustedHop = '198.51.100.10';

$Outer = new TrustedProxy(proxies: ['127.0.0.1', '::1']);
$Inner = new TrustedProxy(proxies: [$trustedHop]);

$Describe = static function (
   string $leg,
   Request $Request,
   Response $Response,
): Response {
   return $Response(body: json_encode([
      'leg' => $leg,
      'URI' => $Request->URI,
      'peer' => $Request->peer,
      'address' => $Request->address,
      'scheme' => $Request->scheme,
      'forwarded_for' => $Request->Header->get('X-Forwarded-For'),
   ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

return new Test(
   description: 'Stacked TrustedProxy instances must authenticate every pass from the immutable peer',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l1/outer-control HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Forwarded-For: 203.0.113.66, 198.51.100.10\r\n"
         . "Connection: close\r\n\r\n",
      static fn (): string => "GET /l1/inner-control HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Forwarded-For: 203.0.113.66, 198.51.100.10\r\n"
         . "Connection: close\r\n\r\n",
      static fn (): string => "GET /l1/stacked-attack HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Forwarded-For: 203.0.113.66, 198.51.100.10\r\n"
         . "Connection: close\r\n\r\n",
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Describe, $Outer, $Inner) {
      yield $Router->route('/l1/outer-control', static function (
         Request $Request,
         Response $Response,
      ) use ($Describe): Response {
         return $Describe('outer-control', $Request, $Response);
      }, GET, middlewares: [$Outer]);

      yield $Router->route('/l1/inner-control', static function (
         Request $Request,
         Response $Response,
      ) use ($Describe): Response {
         return $Describe('inner-control', $Request, $Response);
      }, GET, middlewares: [$Inner]);

      yield $Router->route('/l1/stacked-attack', static function (
         Request $Request,
         Response $Response,
      ) use ($Describe): Response {
         return $Describe('stacked-attack', $Request, $Response);
      }, GET, middlewares: [$Outer, $Inner]);
   },

   test: static function (array $responses) use (
      $forwarded,
      $attackerAddress,
      $trustedHop,
   ): bool|string {
      if (count($responses) !== 3) {
         return 'L1 fixture/control failed: expected three live HTTP responses.';
      }

      $Decode = static function (string $response): null|array {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($response, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      $bodies = array_map($Decode, $responses);
      [$outer, $inner, $attack] = $bodies;

      if (
         $outer === null
         || $inner === null
         || $attack === null
         || str_contains($responses[0], 'HTTP/1.1 200 OK') === false
         || str_contains($responses[1], 'HTTP/1.1 200 OK') === false
         || str_contains($responses[2], 'HTTP/1.1 200 OK') === false
      ) {
         Vars::$labels = ['L1 raw HTTP responses'];
         dump(json_encode($responses, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture/control failed: expected three HTTP 200 JSON responses.';
      }

      $evidence = [
         'outer' => $outer,
         'inner' => $inner,
         'attack' => $attack,
      ];
      $peer = $outer['peer'] ?? null;

      if (
         in_array($peer, ['127.0.0.1', '::1'], true) === false
         || ($inner['peer'] ?? null) !== $peer
         || ($attack['peer'] ?? null) !== $peer
         || $peer === $trustedHop
         || ($outer['forwarded_for'] ?? null) !== $forwarded
         || ($inner['forwarded_for'] ?? null) !== $forwarded
         || ($attack['forwarded_for'] ?? null) !== $forwarded
         || ($outer['scheme'] ?? null) !== 'http'
         || ($inner['scheme'] ?? null) !== 'http'
         || ($attack['scheme'] ?? null) !== 'http'
      ) {
         Vars::$labels = ['L1 transport/header control evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture/control failed: transport peer or forwarding-header preconditions were not preserved.';
      }

      if (
         ($outer['leg'] ?? null) !== 'outer-control'
         || ($outer['URI'] ?? null) !== '/l1/outer-control'
         || ($outer['address'] ?? null) !== $trustedHop
      ) {
         Vars::$labels = ['L1 trusted-peer positive control evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture/control failed: the trusted outer proxy did not resolve the intermediate hop.';
      }

      if (
         ($inner['leg'] ?? null) !== 'inner-control'
         || ($inner['URI'] ?? null) !== '/l1/inner-control'
         || ($inner['address'] ?? null) !== $peer
      ) {
         Vars::$labels = ['L1 untrusted-peer negative control evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture/control failed: the inner proxy trusted the forwarding header without the preceding pass.';
      }

      if (
         ($attack['leg'] ?? null) !== 'stacked-attack'
         || ($attack['URI'] ?? null) !== '/l1/stacked-attack'
      ) {
         Vars::$labels = ['L1 stacked-pipeline route evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture/control failed: the stacked Router pipeline did not reach its expected handler.';
      }

      if (($attack['address'] ?? null) === $attackerAddress) {
         Vars::$labels = ['L1 confirmed source-to-sink evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED L1: a second TrustedProxy trusted X-Forwarded-For after the first pass changed Request::$address to an allowlisted hop, even though immutable Request::$peer remained untrusted.';
      }

      if (($attack['address'] ?? null) !== $trustedHop) {
         Vars::$labels = ['L1 unexpected stacked-pipeline evidence'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'L1 fixture failed: unexpected post-composition address.';
      }

      return true;
   },
);

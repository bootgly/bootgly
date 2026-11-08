<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC (1.2.0 TrustedProxy strategies) — a client behind a trusted
 * edge must never choose `Request::$address` through the new inputs.
 *
 * - relay fallback: no forwarded data picked an address (the trusted socket
 *   peer stands), so a client-written `CF-Connecting-IP` must not be read;
 * - leftmost skip: an invalid first X-Forwarded-For entry must not be skipped
 *   to reach a later, equally client-writable one;
 * - rightmost walk: an invalid untrusted hop ends the walk — entries left of
 *   it are attacker-written.
 *
 * The positive control proves the relay itself is wired: a trusted front
 * proxy in X-Forwarded-For hands over to its visitor header.
 */
$attacker = '203.0.113.66';
$visitor = '203.0.113.9';

$Describe = static function (Request $Request, Response $Response): Response {
   return $Response(body: json_encode([
      'URI' => $Request->URI,
      'peer' => $Request->peer,
      'address' => $Request->address,
   ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

return new Test(
   description: 'TrustedProxy strategies and relay must never let a client choose its address',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /p5/relay-control HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: 173.245.48.5\r\nCF-Connecting-IP: {$visitor}\r\n\r\n",
      static fn (): string => "GET /p5/relay-fallback HTTP/1.1\r\nHost: localhost\r\n"
         . "CF-Connecting-IP: {$attacker}\r\n\r\n",
      static fn (): string => "GET /p5/leftmost-skip HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: garbage, {$attacker}\r\n\r\n",
      static fn (): string => "GET /p5/rightmost-walk HTTP/1.1\r\nHost: localhost\r\n"
         . "X-Forwarded-For: {$attacker}, garbage\r\nConnection: close\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use ($Describe) {
      // ! Built in the server worker — a `relay` registers with that
      //   process's route cache, never with the test runner's
      $Relay = new TrustedProxy(
         proxies: ['127.0.0.1', '::1', '173.245.48.0/20'],
         relay: 'CF-Connecting-IP'
      );
      $Leftmost = new TrustedProxy(proxies: ['127.0.0.1', '::1'], strategy: Strategies::Leftmost);
      $Rightmost = new TrustedProxy(proxies: ['127.0.0.1', '::1']);

      yield $Router->route('/p5/relay-control', $Describe, GET, middlewares: [$Relay]);
      yield $Router->route('/p5/relay-fallback', $Describe, GET, middlewares: [$Relay]);
      yield $Router->route('/p5/leftmost-skip', $Describe, GET, middlewares: [$Leftmost]);
      yield $Router->route('/p5/rightmost-walk', $Describe, GET, middlewares: [$Rightmost]);
   },

   test: static function (array $responses) use ($attacker, $visitor): bool|string {
      $Decode = static function (string $response): null|array {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }
         $decoded = json_decode(substr($response, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };
      $bodies = array_map($Decode, $responses);

      if (count($bodies) !== 4 || in_array(null, $bodies, true)) {
         Vars::$labels = ['Raw HTTP responses'];
         dump(json_encode($responses, JSON_UNESCAPED_SLASHES));

         return 'P5 fixture/control failed: expected four HTTP JSON responses.';
      }
      [$control, $fallback, $leftmost, $rightmost] = $bodies;

      if (($control['address'] ?? null) !== $visitor) {
         Vars::$labels = ['Relay control evidence'];
         dump(json_encode($control, JSON_UNESCAPED_SLASHES));

         return 'P5 fixture/control failed: a trusted front proxy did not hand over to its relay header.';
      }

      if (($fallback['address'] ?? null) === $attacker) {
         return 'CONFIRMED P5-RELAY: the relay header was read on the socket-peer fallback — a client reaching the trusted edge directly chose Request::$address.';
      }
      if (($leftmost['address'] ?? null) === $attacker) {
         return 'CONFIRMED P5-LEFTMOST: an invalid first X-Forwarded-For entry was skipped to reach a client-written one.';
      }
      if (($rightmost['address'] ?? null) === $attacker) {
         return 'CONFIRMED P5-WALK: the right-most walk continued past an invalid untrusted hop into attacker-written entries.';
      }

      foreach ([$fallback, $leftmost, $rightmost] as $body) {
         if (($body['address'] ?? null) !== ($body['peer'] ?? false)) {
            Vars::$labels = ['Unexpected evidence'];
            dump(json_encode($bodies, JSON_UNESCAPED_SLASHES));

            return 'P5 fixture failed: an attack leg resolved to an unexpected address.';
         }
      }

      return true;
   }
);

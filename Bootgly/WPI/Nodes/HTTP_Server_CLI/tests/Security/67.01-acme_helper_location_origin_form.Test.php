<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L2 (2026-07-27) — the HTTP-01 helper must only reflect an
 * origin-form target into its HTTPS `Location`.
 *
 * The port-80 helper matched `(GET|HEAD) (\S+) HTTP` and appended the raw
 * target to a configured HTTPS authority. The authority itself is allowlisted
 * against configured SANs, but the TARGET was not constrained: a raw
 * `:pw@evil.test/path` produced
 * `Location: https://victim.test:pw@evil.test/path`, whose effective host is
 * `evil.test` — an off-origin redirect issued by the victim's own helper.
 * `\S+` also admits NUL, ESC and the rest of C0/DEL into the header value.
 *
 * The real private `answer()` is driven directly, which is the whole helper
 * response path; the surrounding socket loop only feeds it the head.
 *
 * Controls: an ordinary target must still produce a 308 to the configured
 * authority, so the guard cannot pass by refusing everything.
 */
$probe = ['error' => '', 'legs' => []];

return new Test(
   description: 'the ACME HTTP-01 helper must not reflect a non-origin-form target',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      try {
         $Reflection = new ReflectionClass(HTTP_Server_CLI::class);
         $Server = $Reflection->newInstanceWithoutConstructor();
         $answer = $Reflection->getMethod('answer');

         // ! Typed properties the helper reads; the socket loop would have
         //   filled them. Nothing else in answer() touches instance state.
         foreach (['port' => 443, 'domain' => 'victim.test'] as $name => $value) {
            if ($Reflection->hasProperty($name)) {
               $Property = $Reflection->getProperty($name);
               $Property->setValue($Server, $value);
            }
         }

         $Head = static function (string $target): string {
            return "{$target} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         };

         foreach ([
            'attack_userinfo' => 'GET :pw@evil.test/path',
            'attack_protocol_relative' => 'GET //evil.test/path',
            'attack_control_byte' => "GET /a\x00b",
            'attack_absolute_form' => 'GET http://evil.test/path',
            'control_origin_form' => 'GET /ordinary',
         ] as $leg => $line) {
            $probe['legs'][$leg] = (string) $answer->invoke($Server, $Head($line));
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l2-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/l2-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'L2 harness request did not reach /l2-harness.';
      }
      if ($probe['error'] !== '') {
         return 'L2 fixture error: ' . $probe['error'];
      }

      $legs = $probe['legs'];

      // ? Control — an ordinary target must still redirect to the configured
      //   authority, so a guard that refuses everything cannot pass.
      $control = $legs['control_origin_form'] ?? '';
      if (
         ! str_contains($control, '308')
         || ! str_contains($control, 'Location: https://')
         || ! str_contains($control, '/ordinary')
      ) {
         return 'L2 control failed: an ordinary origin-form target no longer produces a 308 '
            . 'to the configured authority: ' . json_encode($control);
      }

      $reflected = [];
      foreach ([
         'attack_userinfo' => 'evil.test',
         'attack_protocol_relative' => 'evil.test',
         'attack_absolute_form' => 'evil.test',
      ] as $leg => $needle) {
         $wire = $legs[$leg] ?? '';
         if (str_contains($wire, 'Location:') && str_contains($wire, $needle)) {
            $reflected[] = "{$leg} → " . json_encode(substr($wire, 0, 120));
         }
      }
      if (str_contains($legs['attack_control_byte'] ?? '', "\x00")) {
         $reflected[] = 'attack_control_byte → a NUL byte reached the response head';
      }

      if ($reflected !== []) {
         return 'CONFIRMED L2: the HTTP-01 helper reflected a non-origin-form target into its '
            . 'Location — ' . implode('; ', $reflected)
            . '. The authority is allowlisted but the target was appended verbatim, so the '
            . 'effective host of the redirect is attacker-chosen.';
      }

      return true;
   },
);

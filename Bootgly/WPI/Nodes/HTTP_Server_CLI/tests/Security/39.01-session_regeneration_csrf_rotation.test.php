<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M6 — session regeneration must invalidate the prior CSRF token.
 *
 * The first middleware models login or privilege elevation: it seeds the real
 * Session with a known pre-authentication token and, for attack cases, calls
 * Session::regenerate(). The production CSRF middleware then receives the old
 * raw or masked token. Controls omit regeneration and must still authenticate
 * the token, proving that the live validation pipeline is functioning.
 */
$knownToken = str_repeat('0123456789abcdef', 4);
$maskedToken = CSRF::mask($knownToken);

return new Specification(
   description: 'Session regeneration must rotate the CSRF token',
   Separator: new Separator(line: true),

   requests: [
      function () use ($knownToken): string {
         return "POST /m6/transfer HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M6-Mode: control-raw\r\n"
            . "X-CSRF-Token: {$knownToken}\r\n"
            . "Content-Length: 0\r\n\r\n";
      },
      function () use ($maskedToken): string {
         return "POST /m6/transfer HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M6-Mode: control-masked\r\n"
            . "X-CSRF-Token: {$maskedToken}\r\n"
            . "Content-Length: 0\r\n\r\n";
      },
      function () use ($knownToken): string {
         return "POST /m6/transfer HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M6-Mode: attack-raw\r\n"
            . "X-CSRF-Token: {$knownToken}\r\n"
            . "Content-Length: 0\r\n\r\n";
      },
      function () use ($maskedToken): string {
         return "POST /m6/transfer HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M6-Mode: attack-masked\r\n"
            . "X-CSRF-Token: {$maskedToken}\r\n"
            . "Content-Length: 0\r\n\r\n";
      },
   ],

   middlewares: [
      new class($knownToken) implements Middleware {
         public function __construct (private string $knownToken)
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';
            $Session = $Request->Session;
            $oldId = $Session->id;

            $Session->set('_csrf_token', $this->knownToken);

            if ($mode === 'attack-raw' || $mode === 'attack-masked') {
               $Session->regenerate();
            }

            $Request->attributes['m6'] = [
               'mode' => $mode,
               'idRotated' => $Session->id !== $oldId,
               'tokenPreserved' => $Session->get('_csrf_token') === $this->knownToken,
            ];

            return $next($Request, $Response);
         }
      },
      new CSRF,
   ],

   response: function (Request $Request, Response $Response): Response {
      $state = $Request->attributes['m6'] ?? [];
      $mode = $state['mode'] ?? 'unknown';
      $rotated = ($state['idRotated'] ?? false) ? 'yes' : 'no';
      $preserved = ($state['tokenPreserved'] ?? false) ? 'yes' : 'no';

      return $Response(
         body: "M6-PROTECTED-HANDLER:{$mode};id_rotated={$rotated};token_preserved={$preserved}"
      );
   },

   test: function (array $responses): bool|string {
      if (count($responses) !== 4) {
         return 'M6 probe did not receive all four CSRF responses.';
      }

      [$rawControl, $maskedControl, $rawAttack, $maskedAttack] = $responses;

      foreach (
         ['control-raw' => $rawControl, 'control-masked' => $maskedControl]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'id_rotated=no;token_preserved=yes')
         ) {
            Vars::$labels = ["M6 {$mode} response"];
            dump(json_encode($response));

            return "M6 {$mode} control failed: the pre-regeneration token was not accepted.";
         }
      }

      $bypasses = [];
      foreach (
         ['attack-raw' => $rawAttack, 'attack-masked' => $maskedAttack]
         as $mode => $response
      ) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "M6-PROTECTED-HANDLER:{$mode}")
         ) {
            if (! str_contains($response, 'id_rotated=yes;token_preserved=yes')) {
               Vars::$labels = ["M6 unexpected {$mode} success response"];
               dump(json_encode($response));

               return "M6 {$mode} reached the handler without proving both ID rotation and token preservation.";
            }

            $bypasses[] = $mode;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 403 Forbidden')
            || ! str_contains($response, 'Invalid CSRF token')
            || str_contains($response, 'M6-PROTECTED-HANDLER:')
         ) {
            Vars::$labels = ["M6 unexpected {$mode} rejection response"];
            dump(json_encode($response));

            return "M6 {$mode} neither executed the handler nor reached the CSRF rejection control.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M6 raw-token bypass', 'M6 masked-token bypass'];
         dump(json_encode($rawAttack), json_encode($maskedAttack));

         return 'CONFIRMED M6: session ID rotation preserved and accepted the old CSRF token: '
            . implode(', ', $bypasses) . '.';
      }

      return true;
   },
);

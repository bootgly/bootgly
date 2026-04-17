<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Request::input()` silently reinterprets an `application/json` body
 * as `application/x-www-form-urlencoded` on JSON parse failure, without
 * consulting `Content-Type`.
 *
 *   try { json_decode(...JSON_THROW_ON_ERROR); }
 *   catch (JsonException) { parse_str($input, $inputs); }
 *
 * Attack scenario — mass-assignment / CSRF-token / HTTP-method confusion:
 *   An endpoint expects JSON (`Content-Type: application/json`). An attacker
 *   submits a body that breaks JSON parsing but is valid urlencoded with
 *   framework-consumed keys:
 *
 *     POST /json-only HTTP/1.1
 *     Content-Type: application/json
 *     Content-Length: 37
 *
 *     _method=DELETE&_token=attacker-owned
 *
 *   `json_decode()` throws → `parse_str()` runs → `$Request->post` returns
 *   `['_method' => 'DELETE', '_token' => 'attacker-owned']`. A handler that
 *   echoes or trusts `$Request->post` has been tricked into honouring
 *   urlencoded nested keys on a JSON-only route.
 *
 * Fixed behaviour: `input()` branches on `Content-Type` first. For
 *   `application/json`, a parse failure returns `[]` — it never falls
 *   through to `parse_str()`.
 *
 * Observed on the wire by echoing `$Request->post` as JSON. A vulnerable
 *   server returns the injected keys; a fixed server returns `[]` / `{}`.
 */

return new Specification(
   description: 'Request::input() must not fall through to parse_str() for application/json',
   Separator: new Separator(line: true),

   request: function (string $hostPort): string {
      // @ Urlencoded payload with framework-consumed keys, sent under
      //   a JSON Content-Type. A vulnerable input() will parse_str() this.
      $body = '_method=DELETE&_token=attacker-owned&admin=1';
      $length = \strlen($body);

      return "POST /json-only HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: application/json\r\n"
         . "Content-Length: {$length}\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $body;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/json-only', function (Request $Request, Response $Response) {
         // @ Force body read, then echo $Request->post as JSON — this is
         //   the application-layer surface where the confusion is
         //   observable (mass-assignment / CSRF-token / _method override).
         $Request->receive();
         return $Response->Json->send($Request->post);
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      // @ Extract response body (after the blank line).
      $sepPos = \strpos($response, "\r\n\r\n");
      if ($sepPos === false) {
         return 'Malformed response (no CRLFCRLF): '
            . \substr($response, 0, 200);
      }
      $body = \substr($response, $sepPos + 4);

      // @ Reach-assertion: require a 200 response so we know the /json-only
      //   handler actually ran — without this, a 404 (handler not installed
      //   by queue-drain races) would falsely pass the leak-check.
      if (! \str_contains($response, '200 OK')) {
         return 'Handler did not run (expected 200 OK from /json-only). '
            . 'Response: ' . \substr($response, 0, 200);
      }

      // @ Any of these attacker-controlled keys appearing in the echoed
      //   $Request->post proves parse_str() ran on a JSON-declared body.
      if (
         str_contains($body, '_method')
         || str_contains($body, '_token')
         || str_contains($body, 'DELETE')
         || str_contains($body, 'attacker-owned')
      ) {
         return 'Request::input() fell through to parse_str() on invalid '
            . 'JSON with Content-Type: application/json — attacker '
            . 'urlencoded keys leaked into $Request->post. Body: '
            . \substr($body, 0, 200);
      }

      return true;
   }
);

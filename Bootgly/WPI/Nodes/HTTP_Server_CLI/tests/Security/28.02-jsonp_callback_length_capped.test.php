<?php

use function str_contains;
use function str_repeat;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `JSONP::send()` echoes an unbounded callback name into the response
 * prefix (audit F-7).
 *
 * The callback validator (`/^[a-zA-Z_$][a-zA-Z0-9_$.]*$/`) blocks `<>()`
 *   injection but caps neither length nor depth, so an attacker can shape an
 *   arbitrarily long attacker-controlled prefix in front of the JSON — useful
 *   for response-splitting-style framing tricks and amplification.
 *
 * The probe sends a 65-character all-identifier callback (passes the regex, so
 *   this exercises the *length* cap specifically, not the charset filter).
 *
 * Fixed behaviour — a callback longer than 64 bytes is rejected and the
 *   formatter falls back to the safe default identifier `callback`.
 */

return new Specification(
   description: 'JSONP must cap callback name length (>64 → safe `callback` fallback)',
   Separator: new Separator(line: true),

   request: function (string $hostPort): string {
      // @ 65 identifier chars — valid per the charset regex, over the 64 cap.
      $callback = str_repeat('a', 65);

      return "GET /jsonp-poc-long?callback={$callback} HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/jsonp-poc-long', function (Request $Request, Response $Response) {
         return $Response->JSONP->send(['x' => 1]);
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (! str_contains($response, '200 OK')) {
         return 'Handler did not run (expected 200 OK from /jsonp-poc-long). '
            . 'Response: ' . substr($response, 0, 200);
      }

      $sepPos = strpos($response, "\r\n\r\n");
      if ($sepPos === false) {
         return 'Malformed response (no CRLFCRLF): ' . substr($response, 0, 200);
      }
      $body = substr($response, $sepPos + 4);

      $oversize = str_repeat('a', 65);

      // ? The attacker-chosen 65-char prefix must NOT reach the body.
      if (str_contains($body, $oversize)) {
         return 'JSONP echoed a 65-char (unbounded) callback name into the '
            . 'response prefix — callback length is not capped. Body: '
            . substr($body, 0, 120);
      }

      // ? Must fall back to the safe default identifier.
      if (! str_contains($body, 'callback({"x":1})')) {
         return 'JSONP did not fall back to the safe `callback` default for an '
            . 'over-length callback name. Body: ' . substr($body, 0, 120);
      }

      return true;
   }
);

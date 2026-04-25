<?php

use function preg_match;
use function str_contains;
use function stripos;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Session strict mode is missing for client-supplied IDs.
 *
 * The `Request->Session` getter accepts any cookie-supplied ID that
 * passes the file handler's `[a-f0-9]{32,64}` path-traversal guard.
 * An attacker who can inject a `PHPSID` cookie (subdomain control,
 * XSS into a sibling app, login-flow trampoline, MITM on plaintext)
 * pre-seeds an ID; the next time a handler mutates the session, the
 * server persists data UNDER the attacker's ID, completing classic
 * session fixation.
 *
 * Vulnerable shape:
 *   Cookie: PHPSID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
 *   ...
 *   $Request->Session->set('user', 'victim');  // -> stored at attacker ID
 *
 * Expected (fixed) behaviour: a client-supplied ID that does not match
 * an existing server-issued session is rotated to a fresh, server-
 * generated ID before any mutation can persist.
 */

return new Specification(
   description: 'Unknown client-supplied session IDs must be rotated before first write',
   Separator: new Separator(line: true),

   request: function (): string {
      // : Attacker-chosen but format-valid PHPSID. No such session
      //   file exists on the server: this is a pre-seeded ID, not a
      //   resumption of an existing session.
      $attackerId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 32 hex chars

      return "GET /session-fixation-strict HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Cookie: PHPSID=" . $attackerId . "\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/session-fixation-strict', function (Request $Request, Response $Response) {
         // : Touch + mutate the session (this is what triggers persistence
         //   and Set-Cookie emission under the bug).
         $Session = $Request->Session;
         $Session->set('user', 'victim');

         return $Response(code: 200, body: 'SID:' . $Session->id);
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      $separator = strpos($response, "\r\n\r\n");
      if ($separator === false) {
         return 'Malformed response (no CRLFCRLF): ' . substr($response, 0, 200);
      }

      $headers = substr($response, 0, $separator);
      $body    = substr($response, $separator + 4);

      $attackerId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

      // @ Body reports the session ID actually used by the handler.
      if (! str_contains($body, 'SID:')) {
         return 'Handler did not report session id: ' . substr($body, 0, 200);
      }

      $usedId = substr($body, strpos($body, 'SID:') + 4);

      if ($usedId === $attackerId) {
         return 'Session fixation: server adopted attacker-supplied PHPSID '
            . '(' . $attackerId . ') and would persist mutations under it. '
            . 'Fix: rotate unknown client-supplied IDs to a fresh server-'
            . 'generated ID before first write (strict mode).';
      }

      // @ Defense-in-depth: a fresh ID must still match the canonical hex
      //   format and must not be empty.
      if (preg_match('/^[a-f0-9]{32,64}$/', $usedId) !== 1) {
         return 'Rotated session id is malformed: ' . $usedId;
      }

      // @ The response must carry a Set-Cookie that does NOT echo back
      //   the attacker-chosen ID (so the client receives only the fresh
      //   server-generated ID).
      if (stripos($headers, 'Set-Cookie: PHPSID=' . $attackerId) !== false) {
         return 'Server echoed attacker PHPSID back via Set-Cookie.';
      }

      return true;
   }
);

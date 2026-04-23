<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Request::$Session` getter unconditionally appends a
 * `Set-Cookie: PHPSID=<id>` header on every access, even when the
 * handler only READS the session (no mutation) and the client never
 * sent a session cookie.
 *
 * Attack surface:
 *   * Session fixation primitive — the server hands out a PHPSID to
 *     any client that touches `$Request->Session`, which stays stable
 *     until a manual `regenerate()` — an attacker who lures the victim
 *     to the server once knows the victim's session id forever.
 *   * DoS — every request that merely reads the session burns
 *     `random_bytes(16)` cycles and emits a cookie header.
 *   * Cache poisoning — `Set-Cookie` makes responses un-cacheable by
 *     any downstream proxy.
 *
 * Vulnerable call shape (any handler that touches the session):
 *   $user = $Request->Session->get('user', 'guest');
 *
 * Expected (fixed) behaviour: `Set-Cookie: PHPSID=...` is emitted
 * ONLY when the session is actually mutated (set/put/delete/.../regenerate).
 * A read-only access MUST NOT emit the cookie.
 */

return new Specification(
   description: 'Request::$Session read-only access must not emit Set-Cookie: PHPSID',
   Separator: new Separator(line: true),

   request: function (): string {
      // : No Cookie header — simulates a first-time visitor.
      return "GET /session-read HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/session-read', function (Request $Request, Response $Response) {
         // : Read-only access — no set(), no put(), no regenerate().
         //   A touch like this happens in many middlewares (auth probes,
         //   flash-message readers, etc). Under the bug, every such probe
         //   emits Set-Cookie unconditionally.
         $user = $Request->Session->get('user', 'guest');
         return $Response(code: 200, body: 'USER:' . (string) $user);
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      $separator = \strpos($response, "\r\n\r\n");
      if ($separator === false) {
         return 'Malformed response (no CRLFCRLF): '
            . \substr($response, 0, 200);
      }
      $headers = \substr($response, 0, $separator);

      // @ Case-insensitive match — some builds lower-case header names.
      if (\stripos($headers, 'Set-Cookie: PHPSID=') !== false) {
         return 'Session fixation primitive: Request::$Session getter '
            . 'emitted `Set-Cookie: PHPSID=` on a read-only access with no '
            . 'prior session cookie. Fix: defer cookie emission until the '
            . 'session is actually mutated (set/put/delete/regenerate/flush).';
      }

      // @ Sanity check: handler executed.
      if (! str_contains($response, 'USER:guest')) {
         return 'Unexpected body (expected USER:guest): '
            . \substr($response, 0, 200);
      }

      return true;
   }
);

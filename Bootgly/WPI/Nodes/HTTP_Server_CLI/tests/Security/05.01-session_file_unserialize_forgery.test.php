<?php

use const BOOTGLY_WORKING_DIR;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Session file handler blindly unserialises any bytes at
 * `workdata/sessions/session_<hex>` (Audit finding #1, CRITICAL).
 *
 * Attack:
 *   `File::read()` returns raw file contents; `Session::__construct` calls
 *   `unserialize($data)` without any integrity check. Anyone with a write
 *   primitive into that directory (shared mount, backup restore, the
 *   decoder's own `downloaded/` tempfiles on the same FS, log-rotation
 *   clobber) can:
 *     (a) forge arbitrary session state for any known session id — instant
 *         privilege escalation / auth confusion;
 *     (b) under native `serialize`/`igbinary`, trigger POP-gadget
 *         deserialisation via any class in vendor/ → RCE.
 *
 *   The PoC demonstrates (a): drop a file containing
 *   `a:1:{s:4:"role";s:5:"admin";}` at `workdata/sessions/session_<hex>`
 *   then request with that `Cookie: PHPSID=<hex>`. Handler queries
 *   `$Session->get('role')` and, without an HMAC guard, reads the forged
 *   `admin`.
 *
 * Expected (fixed) behaviour:
 *   Session payloads MUST carry an HMAC prefix written by `File::write()`.
 *   Files lacking a valid HMAC (as with attacker-dropped files) MUST be
 *   ignored → `Session->data` stays empty → handler observes `role=none`.
 */

$forgedId = null;
$forgedFile = null;

return new Specification(
   description: 'Session file must not deserialise attacker-dropped payloads without HMAC',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort) use (&$forgedId, &$forgedFile): string {
         // ! Simulate an attacker write primitive into the sessions dir.
         $forgedId   = bin2hex(random_bytes(16));
         $forgedFile = BOOTGLY_WORKING_DIR . 'workdata/sessions/session_' . $forgedId;

         // @ Plain PHP-serialised array — no HMAC, no signature.
         file_put_contents($forgedFile, serialize(['role' => 'admin']));

         return "GET /session-takeover HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Cookie: PHPSID={$forgedId}\r\n"
            . "\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/session-takeover', function (Request $Request, Response $Response) {
         $role = $Request->Session->get('role', 'none');
         return $Response(code: 200, body: 'SESSION_ROLE:' . (string) $role);
      }, GET);

      // @ Keep earlier tests' routes alive — the harness's handler queue may
      //   serve priming requests from 2.01 or request from 3.01 using this
      //   handler (see Server::boot() array_shift semantics).
      yield $Router->route('/cache-bleed', function (Request $Request, Response $Response) {
         if (isset($Request->leaked)) {
            return $Response(code: 200, body: 'LEAKED:' . (string) $Request->leaked);
         }
         $Request->leaked = 'attacker-tenant';
         return $Response(code: 200, body: 'CLEAN');
      }, GET);

      yield $Router->route('/smuggle', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SHOULD-NOT-REACH-HANDLER');
      }, POST);
      yield $Router->route('/smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-REACHED');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$forgedId, &$forgedFile): bool|string {
      // @ Cleanup forged file.
      if ($forgedFile !== null && file_exists($forgedFile)) {
         @unlink($forgedFile);
      }

      $response = $responses[0] ?? '';

      if ($response === '') {
         return 'Server returned no response — harness could not read the response.';
      }

      if (str_contains($response, 'SESSION_ROLE:admin')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 300)));
         return 'Handler observed role=admin from an attacker-dropped session '
              . 'file with no HMAC — Session::__construct() unserialised the '
              . 'file contents verbatim. Fix: require an HMAC prefix on '
              . 'session payloads and reject files that do not carry it.';
      }

      if ( ! str_contains($response, 'SESSION_ROLE:none')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 300)));
         return 'Expected SESSION_ROLE:none (empty session — unsigned payload '
              . 'rejected); got unexpected response.';
      }

      return true;
   }
);

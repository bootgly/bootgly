<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Response::redirect()` open-redirect bypass via backslash variants.
 *
 * Current guard (with `$allowExternal === false`):
 *
 *   if (preg_match('#^https?://|^//#i', $URI) === 1) { $URI = '/'; }
 *
 * The regex only blocks `http://`, `https://` and protocol-relative `//`. It
 * does NOT block:
 *   - `/\evil.com/path`   — leading slash + backslash; several user agents
 *                           (IE, some WebView hybrids, email clients) treat
 *                           `\` as `/`, reinterpreting the target as
 *                           `//evil.com/path` → cross-origin redirect.
 *   - `\\evil.com/path`   — pure backslash protocol-relative.
 *   - `javascript:...`    — Location header emitted as-is; email clients and
 *                           embedded WebViews execute the scheme.
 *   - `data:text/html,…`  — same caveat as `javascript:`.
 *
 * Attack scenario — `?next=` style flow:
 *   Any handler that takes a user-supplied redirect target and passes it to
 *   `$Response->redirect($next)` under the default `$allowExternal === false`
 *   contract trusts the guard to neutralise external redirects. A crafted
 *   `next=/\evil.com/login` escapes the guard → phishing / OAuth-redirect
 *   exfil / CSRF-token leak via Referer.
 *
 * Fixed behaviour: any URI containing `\\`, control bytes (`\x00-\x1F \x7F`),
 *   or not starting with a single leading `/` (rejecting `//` and `/\`) is
 *   rewritten to `/`. Dangerous schemes (`javascript:`, `data:`, `vbscript:`,
 *   `file:`) are rejected even when `$allowExternal === true`.
 *
 * This PoC exercises the most diagnostic variant — `/\evil.com/path` — via a
 *   single request. The fix removes all the vectors listed above in one pass.
 */

return new Specification(
   description: 'Response::redirect() must not accept backslash / control-byte open-redirect variants',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /redirect-check HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/redirect-check', function (Request $Request, Response $Response) {
         // : Simulates an app that forwards `?next=` or similar into redirect()
         //   with the default `$allowExternal === false` contract. A vulnerable
         //   guard passes the backslash-variant through verbatim into Location.
         $Response->redirect('/\\evil.com/path');
         return $Response;
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      // @ Extract Location header value (case-insensitive match; the
      //   server emits the exact bytes passed to Header->set()).
      if (! \preg_match('/^Location:\s*(.*)$/mi', $response, $m)) {
         return 'No Location header emitted. Response: '
            . \substr($response, 0, 200);
      }
      $location = \rtrim($m[1], "\r\n ");

      // @ Attack markers: any backslash, the evil host, or a dangerous
      //   scheme in Location proves the guard was bypassed.
      if (
         str_contains($location, 'evil.com')
         || str_contains($location, '\\')
      ) {
         return 'Open-redirect: Response::redirect() accepted a backslash '
            . 'variant ("/\\evil.com/path") under $allowExternal === false. '
            . 'Location header: ' . $location . '. '
            . 'Fix: reject URIs containing `\\`, control bytes (\\x00-\\x1F, \\x7F), '
            . 'not starting with a single leading `/`, or starting with `//` or '
            . '`/\\` — rewrite to `/`. Also reject `javascript:`/`data:`/'
            . '`vbscript:`/`file:` schemes even when $allowExternal === true.';
      }

      // @ Fixed behaviour: Location is rewritten to `/`.
      if ($location !== '/') {
         return 'Unexpected Location header (expected "/"): '
            . $location;
      }

      return true;
   }
);

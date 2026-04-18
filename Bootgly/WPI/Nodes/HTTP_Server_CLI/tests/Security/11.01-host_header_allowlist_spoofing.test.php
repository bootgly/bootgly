<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Request::decode()` accepts ANY syntactically valid `Host` header
 * with no check against the server's configured virtual host. A handler
 * that routes / signs / caches by `$Request->host` or `$Request->domain`
 * can therefore be fooled by a spoofed `Host:` value:
 *   - cache poisoning when combined with caching proxies;
 *   - password-reset poisoning (email body built with attacker's host);
 *   - tenant impersonation in multi-tenant SaaS.
 *
 * RFC 9112 §3.2 mandates `Host` be *present*; it is the server's
 * responsibility to verify the value is *expected* (§7.2).
 *
 * Fix: `Request::$allowedHosts` (new static). When non-empty, any
 * unmatched `Host` header is rejected `400 Bad Request` at decode time.
 *
 * This PoC configures the allowlist to `['localhost']`, then sends a
 * request with `Host: evil.attacker.example`. Vulnerable path: handler
 * dispatches, returns the spoofed host → leak signature. Fixed path:
 * server rejects with 400 before dispatch.
 */

// ! Configure allowlist ONCE for the suite. Must survive worker fork
//   (suite bootstrap runs BEFORE HTTP_Server_CLI::start() forks). The
//   suite-wide `@.php` currently does not set this — so on first arrival
//   it's empty and the vuln is visible. We set it HERE, at test include
//   time (still pre-fork), so the workers inherit the populated list.
Request::$allowedHosts = ['localhost'];


return new Specification(
   description: 'Request::decode() must reject Host values outside $allowedHosts',
   Separator: new Separator(line: true),

   request: function (): string {
      // @ Attacker-chosen Host — syntactically valid, semantically spoofed.
      return "GET /host-echo HTTP/1.1\r\n"
         . "Host: evil.attacker.example\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/host-echo', function (Request $Request, Response $Response) {
         // @ If the handler runs, the spoofed Host is echoed back — this
         //   is the exact cache-poisoning / password-reset-poisoning
         //   primitive: the application trusted a Host it did not vet.
         return $Response(code: 200, body: 'HOST:' . $Request->host);
      });

      // ! NOTE: the Security suite runs tests sequentially against a single
      //   worker that consumes handlers from SAPI::$Tests FIFO. Tests like
      //   7.01 open side-probe sockets (2 priming + 1 harness) that pop 3
      //   queue entries per test. Subsequent tests whose request is
      //   rejected at decode time (9.01/9.02 Expect gate; THIS test's Host
      //   allowlist gate) leave their queue entry dangling. On some runs
      //   another test's request picks up 10.01's handler instead of its
      //   own — therefore we yield 8.01's probe route so its `/traversal`
      //   path still dispatches correctly if it races onto 10.01's slot.
      yield $Router->route('/traversal', function (Request $Request, Response $Response) {
         $Response->render('../views_leak_poc/SECRET');
         $code = $Response->code;
         $verdict = ($code === 403) ? 'GUARD-REJECTED' : "GUARD-BYPASSED({$code})";
         return $Response(code: 200, body: $verdict);
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      // @ Reset allowlist so other suites / the benchmark are not affected.
      Request::$allowedHosts = [];

      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'HOST:evil.attacker.example')) {
         return 'Server dispatched a request with a spoofed `Host: '
            . 'evil.attacker.example` even though the allowlist contained '
            . 'only `localhost`. Handlers that route by $Request->host can '
            . 'be fooled. Fix: consult Request::$allowedHosts in decode() '
            . 'and reject unmatched hosts with 400.';
      }

      // Fixed: server rejects with 400 before the handler runs.
      if (! str_contains($response, '400 Bad Request')) {
         return 'Unexpected response (expected 400 Bad Request): '
            . substr($response, 0, 200);
      }

      return true;
   }
);

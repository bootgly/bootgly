<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;


/**
 * PoC — `CORS` cache-poisoning + permissive defaults (audit F-8).
 *
 * Three issues, each asserted fail-before / pass-after:
 *   1. Reflecting `Origin` into `Access-Control-Allow-Origin` without emitting
 *      `Vary: Origin` lets a shared cache (CDN/proxy) serve origin A's response
 *      (incl. `Allow-Credentials: true`) to origin B.
 *   2. With an allowlist configured but no request `Origin`, `allowOrigin`
 *      fell back to `'*'` — an allowlist must never degrade to wildcard.
 *   3. The default config was `origins: ['*']` (maximally permissive). A CORS
 *      middleware that allows every origin out of the box is a footgun; the
 *      secure default is an empty allowlist (opt-in).
 */

return new Specification(
   description: 'CORS must emit Vary: Origin, never fall back to *, and default to a restrictive allowlist',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ 1: Allowlisted origin is reflected AND Vary: Origin is emitted
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'http://example.com']
      );
      $CORS = new CORS(origins: ['http://example.com']);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Reflected origin must be accompanied by Vary: Origin (cache safety)',
      )
         ->expect(\str_contains((string) $Result->Header->get('Vary'), 'Origin'))
         ->to->be(true)
         ->assert();

      // @ 2: Allowlist + no Origin must NOT fall back to '*'
      [$Request, $Response] = $createMocks();
      $CORS = new CORS(origins: ['http://example.com']);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Allowlist + no Origin must not emit Access-Control-Allow-Origin: *',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Origin'))
         ->not->to->be('*')
         ->assert();
      yield new Assertion(
         description: 'Allowlist + no Origin must still declare Vary: Origin',
      )
         ->expect(\str_contains((string) $Result->Header->get('Vary'), 'Origin'))
         ->to->be(true)
         ->assert();

      // @ 3: Restrictive default — bare CORS rejects a cross-origin request
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'http://evil.com']
      );
      $CORS = new CORS;
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Default (empty allowlist) must reject a cross-origin request with 403',
      )
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      // @ 4: Restrictive default — bare CORS + no Origin emits no wildcard ACAO
      [$Request, $Response] = $createMocks();
      $CORS = new CORS;
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Default + no Origin must not emit Access-Control-Allow-Origin: *',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Origin'))
         ->not->to->be('*')
         ->assert();

      // @ 5: Explicit wildcard still works (regression guard) — constant '*',
      //   no Vary needed (response is origin-independent).
      [$Request, $Response] = $createMocks();
      $CORS = new CORS(origins: ['*']);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Explicit wildcard sets Access-Control-Allow-Origin: *',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Origin'))
         ->to->be('*')
         ->assert();
   })
);

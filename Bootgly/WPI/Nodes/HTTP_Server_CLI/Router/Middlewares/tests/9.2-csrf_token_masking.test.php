<?php

use function str_contains;
use function str_repeat;

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Memory;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;


/**
 * PoC — Audit F-5: BREACH oracle against the CSRF token.
 *
 * The CSRF token is a STABLE per-session secret rendered into the HTML body.
 * With Compression on, a body that also reflects attacker input leaks the
 * token byte-by-byte through the compressed response length (BREACH).
 *
 * Fix: render a per-response MASKED token — `hex(nonce ‖ (token XOR nonce))`,
 * a fresh value each call — so there is no stable secret string in the body.
 * Validation unmasks the submitted token (and still accepts the raw token for
 * migration / non-HTML clients).
 *
 * Before the fix, `CSRF::mask()`/`unmask()` do not exist, so this spec errors.
 */
return new Specification(
   description: 'CSRF token is masked per-response (BREACH-safe) and validates after unmasking',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      $secret = str_repeat('0123456789abcdef', 4); // 64-char hex token

      // # mask/unmask are inverse
      yield (new Assertion(description: 'unmask(mask(token)) recovers the token'))
         ->expect(CSRF::unmask(CSRF::mask($secret)))
         ->to->be($secret)
         ->assert();

      // # mask is non-deterministic (no stable on-wire secret → BREACH-safe)
      yield (new Assertion(description: 'two masks of the same token differ (fresh nonce each render)'))
         ->expect(CSRF::mask($secret) !== CSRF::mask($secret))
         ->to->be(true)
         ->assert();

      // # the masked value never contains the raw secret substring
      yield (new Assertion(description: 'masked token does not embed the raw secret'))
         ->expect(str_contains(CSRF::mask($secret), $secret))
         ->to->be(false)
         ->assert();

      // # a masked token validates on an unsafe method
      $Session = new Memory();
      $Session->set('_csrf_token', $secret);
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-CSRF-Token' => CSRF::mask($secret)],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;
      yield (new Assertion(description: 'POST with a masked token passes (200)'))
         ->expect((new CSRF)->process($Request, $Response, $passthrough)->code)
         ->to->be(200)
         ->assert();

      // # the raw token still validates (migration path)
      $Session = new Memory();
      $Session->set('_csrf_token', $secret);
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-CSRF-Token' => $secret],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;
      yield (new Assertion(description: 'POST with the raw token still passes (200, migration)'))
         ->expect((new CSRF)->process($Request, $Response, $passthrough)->code)
         ->to->be(200)
         ->assert();

      // # a masked token for a DIFFERENT secret is rejected
      $Session = new Memory();
      $Session->set('_csrf_token', $secret);
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-CSRF-Token' => CSRF::mask(str_repeat('f', 64))],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;
      yield (new Assertion(description: 'POST with a masked token for another secret is 403'))
         ->expect((new CSRF)->process($Request, $Response, $passthrough)->code)
         ->to->be(403)
         ->assert();
   })
);

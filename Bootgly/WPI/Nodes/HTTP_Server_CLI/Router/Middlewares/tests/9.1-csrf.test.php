<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Memory;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;


return new Specification(
   description: 'It should enforce CSRF protection on unsafe methods',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      $createSession = function (): object {
         return new Memory();
      };

      // @ Test 1: GET request creates token in session and passes through
      [$Request, $Response] = $createMocks();
      $Session = $createSession();
      $Request->Session = $Session;

      $CSRF = new CSRF;
      $Result = $CSRF->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'GET should pass through with code 200'))
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      yield (new Assertion(description: 'GET should populate session with CSRF token'))
         ->expect($Session->check('_csrf_token'))
         ->to->be(true)
         ->assert();

      $token = $Session->get('_csrf_token');

      yield (new Assertion(description: 'Token should be a non-empty 64-char hex string'))
         ->expect(strlen((string) $token) === 64 && ctype_xdigit((string) $token))
         ->to->be(true)
         ->assert();

      // @ Test 2: POST without token rejected with 403
      [$Request, $Response] = $createMocks(requestProps: ['method' => 'POST']);
      $Request->Session = $createSession();

      $Result = $CSRF->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST without token should return 403'))
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      yield (new Assertion(description: 'POST without token should expose Invalid CSRF token body'))
         ->expect($Result->Body->raw)
         ->to->be('Invalid CSRF token')
         ->assert();

      // @ Test 3: POST with valid token in X-CSRF-Token header passes
      $Session = $createSession();
      $Session->set('_csrf_token', 'abcdef1234567890');
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-CSRF-Token' => 'abcdef1234567890'],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;

      $Result = $CSRF->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST with valid header token should pass'))
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      // @ Test 4: POST with valid token in _token form field passes
      $Session = $createSession();
      $Session->set('_csrf_token', 'formfieldtoken123');
      [$Request, $Response] = $createMocks(requestProps: [
         'method' => 'POST',
         'fields' => ['_token' => 'formfieldtoken123']
      ]);
      $Request->Session = $Session;

      $Result = $CSRF->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST with valid form-field token should pass'))
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      // @ Test 5: POST with mismatched token rejected
      $Session = $createSession();
      $Session->set('_csrf_token', 'expectedtoken');
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-CSRF-Token' => 'wrongtoken'],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;

      $Result = $CSRF->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST with mismatched token should return 403'))
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      // @ Test 6: POST with checkOrigin=true and matching Origin/Host passes
      $Session = $createSession();
      $Session->set('_csrf_token', 'origintoken');
      [$Request, $Response] = $createMocks(
         requestHeaders: [
            'X-CSRF-Token' => 'origintoken',
            'Origin' => 'https://example.com',
            'Host' => 'example.com'
         ],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;

      $CSRFOrigin = new CSRF(checkOrigin: true);
      $Result = $CSRFOrigin->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST with matching Origin and valid token should pass'))
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      // @ Test 7: POST with checkOrigin=true and foreign Origin rejected
      $Session = $createSession();
      $Session->set('_csrf_token', 'origintoken');
      [$Request, $Response] = $createMocks(
         requestHeaders: [
            'X-CSRF-Token' => 'origintoken',
            'Origin' => 'https://attacker.com',
            'Host' => 'example.com'
         ],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;

      $Result = $CSRFOrigin->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'POST with foreign Origin should return 403'))
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      yield (new Assertion(description: 'Foreign Origin response should expose Invalid CSRF origin body'))
         ->expect($Result->Body->raw)
         ->to->be('Invalid CSRF origin')
         ->assert();

      // @ Test 8: Token persists across requests in the same session
      $Session = $createSession();

      [$Request, $Response] = $createMocks();
      $Request->Session = $Session;
      $CSRF->process($Request, $Response, $passthrough);
      $first = $Session->get('_csrf_token');

      [$Request, $Response] = $createMocks();
      $Request->Session = $Session;
      $CSRF->process($Request, $Response, $passthrough);
      $second = $Session->get('_csrf_token');

      yield (new Assertion(description: 'Token should not rotate between requests in same session'))
         ->expect($first === $second && $first !== null)
         ->to->be(true)
         ->assert();

      // @ Test 9: Allowlist with checkOrigin=true accepts whitelisted host
      $Session = $createSession();
      $Session->set('_csrf_token', 'allowtoken');
      [$Request, $Response] = $createMocks(
         requestHeaders: [
            'X-CSRF-Token' => 'allowtoken',
            'Origin' => 'https://trusted.partner.com',
            'Host' => 'example.com'
         ],
         requestProps: ['method' => 'POST']
      );
      $Request->Session = $Session;

      $CSRFAllow = new CSRF(checkOrigin: true, allowedOrigins: ['trusted.partner.com']);
      $Result = $CSRFAllow->process($Request, $Response, $passthrough);

      yield (new Assertion(description: 'Allowlisted Origin should pass'))
         ->expect($Result->code)
         ->to->be(200)
         ->assert();
   })
);

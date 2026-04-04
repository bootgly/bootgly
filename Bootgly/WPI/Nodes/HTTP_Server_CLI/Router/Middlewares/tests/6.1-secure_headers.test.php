<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\SecureHeaders;


return new Specification(
   description: 'It should set all security headers on the response',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: All default security headers are set
      [$Request, $Response] = $createMocks();
      $SecureHeaders = new SecureHeaders;
      $Result = $SecureHeaders->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'X-Content-Type-Options should be nosniff',
      )
         ->expect($Result->Header->get('X-Content-Type-Options'))
         ->to->be('nosniff')
         ->assert();

      yield new Assertion(
         description: 'X-Frame-Options should be SAMEORIGIN',
      )
         ->expect($Result->Header->get('X-Frame-Options'))
         ->to->be('SAMEORIGIN')
         ->assert();

      yield new Assertion(
         description: 'X-XSS-Protection should be 1; mode=block',
      )
         ->expect($Result->Header->get('X-XSS-Protection'))
         ->to->be('1; mode=block')
         ->assert();

      yield new Assertion(
         description: 'Referrer-Policy should be strict-origin-when-cross-origin',
      )
         ->expect($Result->Header->get('Referrer-Policy'))
         ->to->be('strict-origin-when-cross-origin')
         ->assert();

      yield new Assertion(
         description: 'Content-Security-Policy should default to self',
      )
         ->expect($Result->Header->get('Content-Security-Policy'))
         ->to->be("default-src 'self'")
         ->assert();

      yield new Assertion(
         description: 'Permissions-Policy should restrict camera, microphone, geolocation',
      )
         ->expect($Result->Header->get('Permissions-Policy'))
         ->to->be('camera=(), microphone=(), geolocation=()')
         ->assert();

      // @ Test 2: HSTS header is set by default
      yield new Assertion(
         description: 'HSTS should be enabled by default with 1 year max-age',
      )
         ->expect($Result->Header->get('Strict-Transport-Security'))
         ->to->be('max-age=31536000; includeSubDomains')
         ->assert();

      // @ Test 3: HSTS disabled removes the header
      [$Request, $Response] = $createMocks();
      $SecureHeaders = new SecureHeaders(hsts: false);
      $Result = $SecureHeaders->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'HSTS should not be set when disabled',
      )
         ->expect($Result->Header->get('Strict-Transport-Security'))
         ->to->be(Type::Null)
         ->assert();

      // @ Test 4: Custom CSP
      [$Request, $Response] = $createMocks();
      $SecureHeaders = new SecureHeaders(
         contentSecurityPolicy: "default-src 'self'; script-src 'self' cdn.example.com"
      );
      $Result = $SecureHeaders->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Custom CSP should be applied',
      )
         ->expect($Result->Header->get('Content-Security-Policy'))
         ->to->be("default-src 'self'; script-src 'self' cdn.example.com")
         ->assert();
   })
);

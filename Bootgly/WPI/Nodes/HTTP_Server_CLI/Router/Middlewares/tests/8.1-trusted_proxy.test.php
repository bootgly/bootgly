<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;


return new Specification(
   description: 'It should resolve real client IP and scheme from trusted proxies',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: Untrusted proxy — address unchanged
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '10.0.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['127.0.0.1']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Untrusted proxy should not rewrite address',
      )
         ->expect($Request->address)
         ->to->be('10.0.0.1')
         ->assert();

      // @ Test 2: Trusted proxy + X-Forwarded-For rewrites address
      //   XFF: `<client>, <trusted-hop>` from trusted edge 127.0.0.1.
      //   RFC 7239 §5.2: walk from the right, skip trusted hops
      //   (10.0.0.2 is trusted) → first untrusted = 203.0.113.50.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50, 10.0.0.2'],
         requestProps: ['address' => '127.0.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['127.0.0.1', '::1', '10.0.0.2']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Trusted proxy should rewrite address from X-Forwarded-For (right-to-left, skip trusted hops)',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 2b: Multi-hop chain — walk right-to-left, skip trusted hops.
      //   XFF: `<attacker-spoof>, <real-client>, <trusted-hop-1>` from a
      //   trusted edge `127.0.0.1`. RFC-7239 §5.2: real client is the first
      //   untrusted entry walking from the right. Old code returned the
      //   left-most (attacker-declared) IP — chain spoof.
      [$Request, $Response] = $createMocks(
         requestHeaders: [
            'X-Forwarded-For' => '198.51.100.77, 203.0.113.50, 10.0.0.2',
         ],
         requestProps: ['address' => '127.0.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['127.0.0.1', '10.0.0.2']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Multi-hop XFF — real client is first untrusted walking from the right (not the spoofable leftmost)',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 3: Trusted proxy + X-Real-IP rewrites address
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Real-IP' => '198.51.100.10'],
         requestProps: ['address' => '127.0.0.1']
      );
      $TrustedProxy = new TrustedProxy;
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Trusted proxy should rewrite address from X-Real-IP',
      )
         ->expect($Request->address)
         ->to->be('198.51.100.10')
         ->assert();

      // @ Test 4: X-Forwarded-Proto updates scheme
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-Proto' => 'https'],
         requestProps: ['address' => '127.0.0.1', 'scheme' => 'http']
      );
      $TrustedProxy = new TrustedProxy;
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Trusted proxy should update scheme from X-Forwarded-Proto',
      )
         ->expect($Request->scheme)
         ->to->be('https')
         ->assert();

      // @ Test 5: IPv6 loopback trusted by default
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.99'],
         requestProps: ['address' => '::1']
      );
      $TrustedProxy = new TrustedProxy;
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'IPv6 loopback ::1 should be trusted by default',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.99')
         ->assert();
   })
);

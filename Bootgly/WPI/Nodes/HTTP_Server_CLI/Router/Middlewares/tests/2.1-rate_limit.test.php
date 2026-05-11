<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;


return new Specification(
   description: 'It should enforce rate limits per IP with proper headers',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };
      $ip = 'test-rate-limit';
      $Clock = new Clock(100);

      RateLimit::reset();

      try {
         // @ Test 1: First request passes with correct headers
         [$Request, $Response] = $createMocks(requestProps: ['address' => $ip]);
         $RateLimit = new RateLimit(
            limit: 3,
            window: 60,
            clock: fn (): float => $Clock->now
         );
         $Result = $RateLimit->process($Request, $Response, $passthrough);
         yield new Assertion(
            description: 'First request should pass',
         )
            ->expect($Result->code)
            ->to->be(200)
            ->assert();

         yield new Assertion(
            description: 'X-RateLimit-Limit header should be set',
         )
            ->expect($Result->Header->get('X-RateLimit-Limit'))
            ->to->be('3')
            ->assert();

         yield new Assertion(
            description: 'X-RateLimit-Remaining should be limit - 1',
         )
            ->expect($Result->Header->get('X-RateLimit-Remaining'))
            ->to->be('2')
            ->assert();

         yield new Assertion(
            description: 'X-RateLimit-Reset should use the fake clock window',
         )
            ->expect($Result->Header->get('X-RateLimit-Reset'))
            ->to->be('160')
            ->assert();

         // @ Test 2: Exhaust the limit
         // Second request (remaining: 1)
         [$Request, $Response] = $createMocks(requestProps: ['address' => $ip]);
         $RateLimit->process($Request, $Response, $passthrough);
         // Third request (remaining: 0)
         [$Request, $Response] = $createMocks(requestProps: ['address' => $ip]);
         $Result = $RateLimit->process($Request, $Response, $passthrough);
         yield new Assertion(
            description: 'Remaining should be 0 at limit',
         )
            ->expect($Result->Header->get('X-RateLimit-Remaining'))
            ->to->be('0')
            ->assert();

         // @ Test 3: Over limit returns 429
         [$Request, $Response] = $createMocks(requestProps: ['address' => $ip]);
         $Result = $RateLimit->process($Request, $Response, $passthrough);
         yield new Assertion(
            description: 'Request over limit should return 429',
         )
            ->expect($Result->code)
            ->to->be(429)
            ->assert();

         yield new Assertion(
            description: 'Retry-After header should be set when rate limited',
         )
            ->expect($Result->Header->get('Retry-After'))
            ->to->be('60')
            ->assert();

         // @ Test 4: Window resets after deterministic time advance
         $Clock->advance(60);
         [$Request, $Response] = $createMocks(requestProps: ['address' => $ip]);
         $Result = $RateLimit->process($Request, $Response, $passthrough);

         yield new Assertion(
            description: 'Request after window reset should pass',
         )
            ->expect($Result->code)
            ->to->be(200)
            ->assert();

         yield new Assertion(
            description: 'Remaining should reset after fake clock advances one window',
         )
            ->expect($Result->Header->get('X-RateLimit-Remaining'))
            ->to->be('2')
            ->assert();

         yield new Assertion(
            description: 'Reset header should move to the next deterministic window',
         )
            ->expect($Result->Header->get('X-RateLimit-Reset'))
            ->to->be('220')
            ->assert();
      }
      finally {
         RateLimit::reset();
      }
   })
);

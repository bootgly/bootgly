<?php

use function str_repeat;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\BodyParser;


return new Specification(
   description: 'It should validate request body size and reject oversized payloads',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: Small body passes through
      [$Request, $Response] = $createMocks(
         requestProps: ['input' => 'Hello']
      );
      $BodyParser = new BodyParser(maxSize: 100);
      $Result = $BodyParser->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Small body should pass through',
      )
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      // @ Test 2: Large Content-Length header returns 413
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Content-Length' => '200'],
         requestProps: ['input' => '']
      );
      $BodyParser = new BodyParser(maxSize: 100);
      $Result = $BodyParser->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Large Content-Length should return 413 Payload Too Large',
      )
         ->expect($Result->code)
         ->to->be(413)
         ->assert();

      // @ Test 3: Large actual body returns 413
      [$Request, $Response] = $createMocks(
         requestProps: ['input' => str_repeat('x', 200)]
      );
      $BodyParser = new BodyParser(maxSize: 100);
      $Result = $BodyParser->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Large body should return 413 Payload Too Large',
      )
         ->expect($Result->code)
         ->to->be(413)
         ->assert();

      // @ Test 4: Exact limit passes through
      [$Request, $Response] = $createMocks(
         requestProps: ['input' => str_repeat('x', 100)]
      );
      $BodyParser = new BodyParser(maxSize: 100);
      $Result = $BodyParser->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Body at exact limit should pass through',
      )
         ->expect($Result->code)
         ->to->be(200)
         ->assert();
   })
);

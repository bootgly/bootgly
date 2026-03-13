<?php

use function hash;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\ETag;


return new Specification(
   description: 'It should generate ETag headers and return 304 on match',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $body = 'Hello World!';
      $expectedHash = hash('xxh3', $body);
      $expectedWeakETag = 'W/"' . $expectedHash . '"';
      $expectedStrongETag = '"' . $expectedHash . '"';

      // @ Test 1: ETag header is generated (weak by default)
      [$Request, $Response] = $createMocks();
      $handler = function (object $Request, object $Response) use ($body): object {
         $Response->Body->raw = $body;
         return $Response;
      };
      $ETag = new ETag;
      $Result = $ETag->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Weak ETag should be generated with W/ prefix',
      )
         ->expect($Result->Header->get('ETag'))
         ->to->be($expectedWeakETag)
         ->assert();

      // @ Test 2: Strong ETag format
      [$Request, $Response] = $createMocks();
      $handler = function (object $Request, object $Response) use ($body): object {
         $Response->Body->raw = $body;
         return $Response;
      };
      $ETag = new ETag(weak: false);
      $Result = $ETag->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Strong ETag should not have W/ prefix',
      )
         ->expect($Result->Header->get('ETag'))
         ->to->be($expectedStrongETag)
         ->assert();

      // @ Test 3: Matching If-None-Match returns 304
      [$Request, $Response] = $createMocks(
         requestHeaders: ['If-None-Match' => $expectedWeakETag]
      );
      $handler = function (object $Request, object $Response) use ($body): object {
         $Response->Body->raw = $body;
         return $Response;
      };
      $ETag = new ETag;
      $Result = $ETag->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Matching If-None-Match should return 304 Not Modified',
      )
         ->expect($Result->code)
         ->to->be(304)
         ->assert();

      // @ Test 4: Empty body skips ETag generation
      [$Request, $Response] = $createMocks();
      $handler = function (object $Request, object $Response): object {
         $Response->Body->raw = '';
         return $Response;
      };
      $ETag = new ETag;
      $Result = $ETag->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Empty body should not generate ETag',
      )
         ->expect($Result->Header->get('ETag'))
         ->to->be(Type::Null)
         ->assert();
   })
);

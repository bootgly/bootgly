<?php

use function gzdecode;
use function str_repeat;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Compression;


return new Specification(
   description: 'It should compress response body when Accept-Encoding allows it',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $largeBody = str_repeat('Hello World! ', 100); // ~1300 bytes

      // @ Test 1: Small body skips compression
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Accept-Encoding' => 'gzip']
      );
      $handler = function (object $Request, object $Response): object {
         $Response->Body->raw = 'Small';
         return $Response;
      };
      $Compression = new Compression(minSize: 1024);
      $Result = $Compression->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Small body should not be compressed',
      )
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be(Type::Null)
         ->assert();

      yield new Assertion(
         description: 'Small body should remain unchanged',
      )
         ->expect($Result->Body->raw)
         ->to->be('Small')
         ->assert();

      // @ Test 2: Large body with gzip Accept-Encoding gets compressed
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Accept-Encoding' => 'gzip, deflate']
      );
      $handler = function (object $Request, object $Response) use ($largeBody): object {
         $Response->Body->raw = $largeBody;
         return $Response;
      };
      $Compression = new Compression(minSize: 1024);
      $Result = $Compression->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'Large body should be gzip-encoded',
      )
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be('gzip')
         ->assert();

      yield new Assertion(
         description: 'Decompressed body should match original',
      )
         ->expect(gzdecode($Result->Body->raw))
         ->to->be($largeBody)
         ->assert();

      yield new Assertion(
         description: 'Vary header should be set to Accept-Encoding',
      )
         ->expect($Result->Header->get('Vary'))
         ->to->be('Accept-Encoding')
         ->assert();

      // @ Test 3: No Accept-Encoding skips compression
      [$Request, $Response] = $createMocks();
      $handler = function (object $Request, object $Response) use ($largeBody): object {
         $Response->Body->raw = $largeBody;
         return $Response;
      };
      $Compression = new Compression(minSize: 1024);
      $Result = $Compression->process($Request, $Response, $handler);
      yield new Assertion(
         description: 'No Accept-Encoding should skip compression',
      )
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be(Type::Null)
         ->assert();

      yield new Assertion(
         description: 'Body should remain unchanged without Accept-Encoding',
      )
         ->expect($Result->Body->raw)
         ->to->be($largeBody)
         ->assert();
   })
);

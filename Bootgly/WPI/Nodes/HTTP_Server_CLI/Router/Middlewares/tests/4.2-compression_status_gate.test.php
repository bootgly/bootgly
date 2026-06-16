<?php

use function str_repeat;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Compression;


/**
 * PoC — `Compression` re-encodes every response, including 4xx/5xx error and
 *   auth-challenge bodies (audit F-11).
 *
 * Before the fix, any body ≥ `minSize` with a matching `Accept-Encoding` was
 *   gzipped regardless of status. Fixed behaviour gates compression on 2xx/3xx,
 *   so error and auth-challenge representations are left untouched.
 */

return new Specification(
   description: 'Compression must skip 4xx/5xx error and auth-challenge bodies',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $largeBody = str_repeat('Hello World! ', 100); // ~1300 bytes

      // @ 1: Regression — a 200 body is still compressed.
      [$Request, $Response] = $createMocks(requestHeaders: ['Accept-Encoding' => 'gzip']);
      $okHandler = function (object $Request, object $Response) use ($largeBody): object {
         $Response->Body->raw = $largeBody;
         return $Response;
      };
      $Result = (new Compression(minSize: 1024))->process($Request, $Response, $okHandler);
      yield new Assertion(description: '200 large body is gzip-encoded')
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be('gzip')
         ->assert();

      // @ 2: A 500 error body must NOT be compressed (status gate).
      [$Request, $Response] = $createMocks(requestHeaders: ['Accept-Encoding' => 'gzip']);
      $errorHandler = function (object $Request, object $Response) use ($largeBody): object {
         $Response->code = 500;
         $Response->Body->raw = $largeBody;
         return $Response;
      };
      $Result = (new Compression(minSize: 1024))->process($Request, $Response, $errorHandler);
      yield new Assertion(description: '500 error body is not compressed')
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be(Type::Null)
         ->assert();
      yield new Assertion(description: '500 error body left unchanged')
         ->expect($Result->Body->raw)
         ->to->be($largeBody)
         ->assert();

      // @ 3: A 401 auth-challenge body must NOT be compressed.
      [$Request, $Response] = $createMocks(requestHeaders: ['Accept-Encoding' => 'gzip']);
      $authHandler = function (object $Request, object $Response) use ($largeBody): object {
         $Response->code = 401;
         $Response->Body->raw = $largeBody;
         return $Response;
      };
      $Result = (new Compression(minSize: 1024))->process($Request, $Response, $authHandler);
      yield new Assertion(description: '401 auth-challenge body is not compressed')
         ->expect($Result->Header->get('Content-Encoding'))
         ->to->be(Type::Null)
         ->assert();
   })
);

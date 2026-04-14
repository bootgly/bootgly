<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


use Closure;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification as Base;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server\Handling;
use Bootgly\API\Workables\Server\Middleware;


class Specification extends Base implements Handling
{
   // * Config
   // ...inherited

   // * Data
   // ...inherited
   /**
    * @var null|Closure
    * A closure that returns a raw HTTP request string to be used in single-request E2E tests.
    * Mutually exclusive with $requests.
    */
   public null|Closure $request;
   /**
    * @var array<Closure>
    * An array of closures, each returning a raw HTTP request string.
    * When non-empty, the test is "multi-request" — $request (singular) is ignored.
    */
   public array $requests;
   /**
    * @var array<Middleware>
    * An array of middleware instances to be used in E2E tests.
    */
   public array $middlewares;
   /**
    * @var Closure
    * A closure that returns a raw HTTP response to be used in E2E tests.
    */
   public Closure $response;
   /**
    * @var null|int
    * The length of the expected HTTP response.
    */
   public null|int $responseLength;
   /**
    * @var array<null|int>
    * The expected response lengths per request (for multi-request tests).
    */
   public array $responseLengths;


   /**
    * @param array<Closure> $requests
    * @param array<Middleware> $middlewares
    * @param array<null|int> $responseLengths
    */
   public function __construct (
      // * Data (required)
      Closure $response,
      Assertions|Closure $test,
      // * Config (optional - inherited)
      null|string $description = null,
      null|Separator $Separator = null,
      bool $skip = false,
      bool $ignore = false,
      null|Closure $retest = null,
      // * Data (optional - E2E)
      null|Closure $request = null,
      array $requests = [],
      array $middlewares = [],
      null|int $responseLength = null,
      array $responseLengths = [],
   )
   {
      // @
      if ($request === null && $requests === []) {
         throw new InvalidArgumentException(
            'Either "request" or "requests" must be provided.'
         );
      }
      if ($request !== null && $requests !== []) {
         throw new InvalidArgumentException(
            '"request" and "requests" are mutually exclusive.'
         );
      }

      parent::__construct(
         test: $test,
         description: $description,
         Separator: $Separator,
         skip: $skip,
         ignore: $ignore,
         retest: $retest,
      );

      // * Data
      $this->request = $request;
      $this->requests = $requests;
      $this->response = $response;
      $this->middlewares = $middlewares;
      $this->responseLength = $responseLength;
      $this->responseLengths = $responseLengths;
   }
}

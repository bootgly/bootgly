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

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification as Base;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Server\Handling;
use Bootgly\API\Server\Middleware;


class Specification extends Base implements Handling
{
   // * Config
   // ...inherited

   // * Data
   // ...inherited
   /**
    * @var Closure
    * A closure that returns a raw HTTP request string to be used in E2E tests.
    */
   public Closure $request;
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
    * @param array<Middleware> $middlewares
    */
   public function __construct (
      // * Data (required)
      Closure $request,
      Closure $response,
      Assertions|Closure $test,
      // * Config (optional - inherited)
      null|string $description = null,
      null|Separator $Separator = null,
      bool $skip = false,
      bool $ignore = false,
      null|Closure $retest = null,
      // * Data (optional - E2E)
      array $middlewares = [],
      null|int $responseLength = null,
   )
   {
      // @
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
      $this->response = $response;
      $this->middlewares = $middlewares;
      $this->responseLength = $responseLength;
   }
}

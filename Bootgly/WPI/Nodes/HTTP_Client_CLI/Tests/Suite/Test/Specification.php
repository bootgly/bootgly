<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


use function count;
use Closure;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification as Base;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


/**
 * E2E Test Specification for HTTP_Client_CLI.
 *
 * This class defines test cases where a mock TCP server sends raw HTTP responses
 * and the HTTP_Client_CLI processes them. Tests validate the decoded Response object.
 *
 * Pattern (inverted from HTTP_Server_CLI):
 * - Mock TCP Server → sends raw HTTP response
 * - HTTP_Client_CLI → processes and decodes
 * - Test → validates decoded Response
 */
class Specification extends Base
{
   // * Config
   // ...inherited

   // * Data
   // ...inherited

   /**
    * HTTP response factory the mock server should call.
    * It must return a string or Generator.
    * A Generator allows yielding response in chunks (e.g., interim + final).
    * The Closure receives the raw request data as argument.
    *
    * @var Closure
    */
   public Closure $response;

   /**
    * Closure that configures and triggers the HTTP client request.
    * Receives the HTTP_Client_CLI instance.
    * Should call $Client->request() or similar.
    *
    * @var Closure
    */
   public Closure $request;

   /**
    * Array of HTTP response factories for multi-request tests.
    * When non-empty, $response is ignored.
    * Each Closure must return a string or Generator.
    *
    * @var array<Closure>
    */
   public array $responses;

   /**
    * Array of request closures for multi-request tests.
    * When non-empty, $request is ignored.
    *
    * @var array<Closure>
    */
   public array $requests;

   /**
    * Expected response length hint for reading.
    * null = auto-detect via Content-Length or chunked.
    *
    * @var null|int
    */
   public null|int $responseLength;

   /**
    * Per-request response lengths for multi-request tests.
    *
    * @var array<null|int>
    */
   public array $responseLengths;


   /**
   * @param Closure $response HTTP response factory returning string|Generator.
    * @param Closure $request Closure that triggers the HTTP client request.
    * @param Assertions|Closure $test Test closure receiving the decoded Response.
    * @param null|string $description Human-readable test description.
    * @param null|Separator $Separator Visual separator for test output.
    * @param bool $skip Skip this test.
    * @param bool $ignore Ignore test failures.
    * @param null|Closure $retest Closure for re-testing.
      * @param array<Closure> $responses Multi-request response factories.
    * @param array<Closure> $requests Multi-request closures.
    * @param null|int $responseLength Expected response length.
    * @param array<null|int> $responseLengths Per-request response lengths.
    */
   public function __construct (
      // * Data (required)
         Closure $response,
      Closure $request,
      Assertions|Closure $test,
      // * Config (optional - inherited)
      null|string $description = null,
      null|Separator $Separator = null,
      bool $skip = false,
      bool $ignore = false,
      null|Closure $retest = null,
      // * Data (optional - E2E multi-request)
      array $responses = [],
      array $requests = [],
      null|int $responseLength = null,
      array $responseLengths = [],
   )
   {
      foreach ($responses as $responseFactory) {
         if ($responseFactory instanceof Closure === false) { // @phpstan-ignore instanceof.alwaysTrue, identical.alwaysFalse
            throw new InvalidArgumentException(
               'Each "responses" entry must be a Closure instance.'
            );
         }
      }

      // @ Validate multi-request consistency
      if ($responses !== [] && $requests !== []) {
         if (count($responses) !== count($requests)) {
            throw new InvalidArgumentException(
               'responses and requests arrays must have the same length.'
            );
         }
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
      $this->response = $response;
      $this->request = $request;
      $this->responses = $responses;
      $this->requests = $requests;
      $this->responseLength = $responseLength;
      $this->responseLengths = $responseLengths;
   }
}

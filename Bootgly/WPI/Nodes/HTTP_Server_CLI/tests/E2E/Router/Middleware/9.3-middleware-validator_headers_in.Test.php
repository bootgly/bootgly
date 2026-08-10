<?php

use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ? MW-7 regression — before the fix a canonically-cased non-implicit rule
//   never bound, so In() was a silent no-op and the handler ran on the
//   unconstrained value (fails open). It must reject 422, keyed by the
//   user's original casing, without a bogus Required error.
return new Test(
   description: 'It should enforce canonically-cased header rules against bad values',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nX-API-Key: abc123\r\nX-API-Version: DROP TABLE users\r\n\r\n";
   },
   middlewares: [
      new Validator(rules: [
         'X-API-Key' => [new Required],
         'X-API-Version' => [new In(['2024-01', '2025-06'])],
      ], Source: Sources::Headers)
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'headers handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 422 Unprocessable Entity')
         && str_contains($response, 'X-API-Version must be one of the allowed values.')
         && str_contains($response, 'X-API-Key is required.') === false
         && str_contains($response, 'headers handler executed') === false
            ?: 'Canonically-cased header rule did not fail closed on a bad value';
   }
);

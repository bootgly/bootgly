<?php

use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ? MW-8 regression — before the fix a non-implicit cookie rule never
//   bound, so In() was a silent no-op and the handler ran on the
//   unconstrained value (fails open), while Required 422d for the wrong
//   reason. It must reject 422 keyed by the offending cookie only.
return new Test(
   description: 'It should enforce cookie rules against bad values',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session_id=abc123; theme=DROP TABLE users\r\n\r\n";
   },
   middlewares: [
      new Validator(rules: [
         'session_id' => [new Required],
         'theme' => [new In(['light', 'dark'])],
      ], Source: Sources::Cookies)
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'cookies handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 422 Unprocessable Entity')
         && str_contains($response, 'theme must be one of the allowed values.')
         && str_contains($response, 'session_id is required.') === false
         && str_contains($response, 'cookies handler executed') === false
            ?: 'Cookie rule did not fail closed on a bad value';
   }
);

<?php

use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ? MW-8 regression — the parser builds $Request->cookies as a list of
//   per-line maps, so before the fix no rule key could ever bind a cookie
//   value: Required fired a bogus 422 on a cookie the client DID send.
return new Test(
   description: 'It should bind cookie rules by cookie name',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session_id=abc123; theme=dark\r\n\r\n";
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
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'cookies handler executed')
         && str_contains($response, 'session_id is required.') === false
            ?: 'Cookie rule did not bind to the sent cookie';
   }
);

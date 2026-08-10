<?php

use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ? MW-8 regression — the parser keeps one map per wire Cookie line, and
//   flatten() must union ALL lines: a cookie delivered only on the second
//   line still binds (Required), while a name duplicated across lines
//   resolves first-line-wins, mirroring Cookies::get(). A first-line-only
//   or last-line-wins flatten fails this spec.
return new Test(
   description: 'It should union every Cookie line with first-line-wins duplicates',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: dup=first; a=1\r\nCookie: dup=second; b=2\r\n\r\n";
   },
   middlewares: [
      new Validator(rules: [
         'b' => [new Required],
         'dup' => [new In(['first'])],
      ], Source: Sources::Cookies)
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'cookies handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'cookies handler executed')
         && str_contains($response, 'b is required.') === false
            ?: 'Cookie lines were not unioned first-line-wins';
   }
);

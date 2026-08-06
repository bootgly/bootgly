<?php

use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ? MW-7 regression — the parser lowercases header field names (RFC 9110
//   §5.1), so a canonically-cased rule key must still bind to the wire
//   field. Before the fix, Required fired a bogus 422 on a header the
//   client DID send and the handler never ran.
return new Specification(
   description: 'It should bind canonically-cased header rules to the wire field',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nX-API-Key: abc123\r\n\r\n";
   },
   middlewares: [
      new Validator(rules: [
         'X-API-Key' => [new Required],
      ], Source: Sources::Headers)
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'headers handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'headers handler executed')
         && str_contains($response, 'X-API-Key is required.') === false
            ?: 'Canonically-cased header rule did not bind to the sent header';
   }
);

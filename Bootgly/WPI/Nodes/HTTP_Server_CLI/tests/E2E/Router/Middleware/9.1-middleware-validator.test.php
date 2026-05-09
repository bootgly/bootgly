<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Email;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject invalid requests before the route handler',

   request: function () {
      $body = '{"email":"invalid"}';
      $length = strlen($body);

      return "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: {$length}\r\n\r\n{$body}";
   },
   middlewares: [
      new Validator(rules: [
         'email' => [new Required, new Email],
      ], Source: Sources::Fields)
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 422 Unprocessable Entity')
         && str_contains($response, 'Content-Type: application/json')
         && str_contains($response, 'email must be a valid email address.')
         && str_contains($response, 'handler executed') === false
            ?: 'Validator middleware did not fail closed';
   }
);

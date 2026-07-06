<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Content negotiation serves JSON for Accept: application/json',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->Negotiation->send(['unsafe' => '<b>'], view: 'inherited');
   },

   test: function ($response) {
      if (str_contains($response, '200 OK') === false) {
         return "Status is not 200 OK: \n" . $response;
      }
      if (str_contains($response, 'Content-Type: application/json') === false) {
         return "Content-Type is not application/json: \n" . $response;
      }
      // Negotiated responses vary by Accept — caches must not mix representations
      if (str_contains($response, 'Vary: Accept') === false) {
         return "Vary: Accept header is missing: \n" . $response;
      }
      if (str_contains($response, '{"unsafe":"<b>"}') === false) {
         return "JSON body not matched: \n" . $response;
      }

      return true;
   }
);

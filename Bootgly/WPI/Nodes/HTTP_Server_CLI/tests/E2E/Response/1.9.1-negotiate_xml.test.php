<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Content negotiation serves XML for Accept: application/xml',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAccept: application/xml\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->Negotiation->send(['unsafe' => '<b>'], view: 'inherited');
   },

   test: function ($response) {
      if (str_contains($response, '200 OK') === false) {
         return "Status is not 200 OK: \n" . $response;
      }
      if (str_contains($response, 'Content-Type: application/xml') === false) {
         return "Content-Type is not application/xml: \n" . $response;
      }
      if (str_contains($response, '<response><unsafe>&lt;b&gt;</unsafe></response>') === false) {
         return "XML body not matched: \n" . $response;
      }

      return true;
   }
);

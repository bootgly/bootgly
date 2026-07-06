<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Content negotiation replies 406 when no offer satisfies Accept',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAccept: image/png\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->Negotiation->send(['unsafe' => '<b>'], view: 'inherited');
   },

   test: function ($response) {
      if (str_contains($response, '406 Not Acceptable') === false) {
         return "Status is not 406 Not Acceptable: \n" . $response;
      }

      return true;
   }
);

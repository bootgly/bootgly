<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'An Accept refusing every offer with q=0 yields 406, not the default',

   request: function () {
      // ! q=0 means "not acceptable" (RFC 9110) — the header is PRESENT, so
      //   this must 406 instead of falling back to the JSON default.
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAccept: application/json;q=0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->Negotiation->send(['unsafe' => '<b>'], view: 'inherited');
   },

   test: function ($response) {
      if (str_contains($response, '406 Not Acceptable') === false) {
         return "Status is not 406 Not Acceptable: \n" . $response;
      }
      if (str_contains($response, 'Vary: Accept') === false) {
         return "406 is missing Vary: Accept: \n" . $response;
      }

      return true;
   }
);

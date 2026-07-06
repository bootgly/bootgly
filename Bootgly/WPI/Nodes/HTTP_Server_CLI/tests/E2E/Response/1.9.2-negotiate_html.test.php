<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Content negotiation renders the HTML view for Accept: text/html',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->Negotiation->send(['unsafe' => '<b>'], view: 'inherited');
   },

   test: function ($response) {
      if (str_contains($response, '200 OK') === false) {
         return "Status is not 200 OK: \n" . $response;
      }
      if (str_contains($response, 'Content-Type: text/html') === false) {
         return "Content-Type is not text/html: \n" . $response;
      }
      // The `inherited` view composes @extends + @include + @>> (escaped output)
      if (str_contains($response, '<l>C|F|&lt;b&gt;</l>') === false) {
         return "Rendered HTML view body not matched: \n" . $response;
      }

      return true;
   }
);

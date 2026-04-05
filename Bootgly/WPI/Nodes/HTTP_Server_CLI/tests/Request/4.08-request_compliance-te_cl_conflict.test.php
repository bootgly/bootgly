<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject Transfer-Encoding + Content-Length conflict with 400',

   request: function () {
      return "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nTransfer-Encoding: chunked\r\n\r\nhello";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      // @ Assert
      if ($response === '') {
         return true;
      }

      if (str_contains($response, '400 Bad Request')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have rejected with 400 (TE + CL conflict)';
   }
);

<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject unknown Expect value with 417',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nExpect: something-weird\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      // @ Assert
      if ($response === '') {
         return true; // Connection was rejected
      }

      if (str_contains($response, '417 Expectation Failed')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have rejected with 417 Expectation Failed';
   }
);

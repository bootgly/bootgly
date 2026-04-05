<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should respond with Connection: close when client requests it',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'bye');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, '200 OK') && str_contains($response, 'Connection: close')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Response should contain Connection: close header';
   }
);

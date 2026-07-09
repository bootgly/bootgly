<?php

use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/production HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      Catcher::$Environment = Environments::Production;

      throw new Exception('production secret probe');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'Status 500 not found';
      }
      if (str_contains($response, '<h1>500</h1>') === false) {
         return 'Built-in clean page not found';
      }
      if (str_contains($response, 'Internal Server Error</p>') === false) {
         return 'Status message not found in the clean page';
      }
      if (str_contains($response, 'production secret probe')) {
         return 'Throwable message leaked in production';
      }
      if (str_contains($response, 'Exception') && str_contains($response, 'id="context"')) {
         return 'Debug page leaked in production';
      }

      return true;
   }
);

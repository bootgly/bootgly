<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // return $Request->get('/');
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $port = (string) $Request->port;
      return $Response(body: $port);
   },

   test: function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->code === ...;
      */

      $lines = explode("\r\n", $response);
      $lastLine = $lines[count($lines) - 1];

      $code = 0;
      if ($lastLine) {
         $code = (int) $lastLine;
      }

      // @ Assert
      if ( !($code > 1000 && $code < 65535) ) {
         Vars::$labels = ['HTTP Code:'];
         dump($lastLine);
         return 'Response raw not matched';
      }

      return true;
   }
);

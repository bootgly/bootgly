<?php
use Bootgly\ABI\Debugging\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.1\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $protocol = $Request->protocol;
      return $Response(content: $protocol);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 8\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      HTTP/1.1
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];

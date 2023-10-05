<?php
use Bootgly\ACI\Debugger;
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
      // return $Request->get('/test/foo?query=abc&query2=xyz');
      return "GET /test/foo?query=abc&query2=xyz HTTP/1.1\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $queries = $Request->queries;
      return $Response->Json->send($queries);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 30\r
      \r
      {"query":"abc","query2":"xyz"}
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];

<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure

   // @ simulate
   // Client API
   'capi' => function () {
      // return $Request->get('/test/foo?query=abc&query2=xyz');
      return "GET /test/foo?query=abc&query2=xyz HTTP/1.1\r\n\r\n";
   },
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $urn = $Request->urn;
      return $Response(content: $urn);
   },

   // @ test
   'test' => function ($response) : bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 3\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      foo
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Request not matched';
   }
];

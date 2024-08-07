<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication\Basic;
// SAPI
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separator.header' => '@authenticate',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $Response(body: 'Unauthorized page!');
      return $Response->authenticate(new Basic());
   },
   // Client API
   'request' => function () {
      // return $Request->get('/test/auth/1');
      return "GET /test/auth/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
      // ! Asserts
      // @ Assert response raw
      $expected = <<<HTML_RAW
      HTTP/1.1 401 Unauthorized\r
      Server: Bootgly\r
      WWW-Authenticate: Basic realm="Protected area"\r
      Content-Length: 18\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Unauthorized page!
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

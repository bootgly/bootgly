<?php

use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\nodes\HTTP\Server\Request;
use Bootgly\WPI\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure

   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      GET / HTTP/1.1\r
      Cookie: cookie1=value1; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      Cookie: cookie2=value2; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      Cookie: cookie3=value3; Expires=Wed, 21 Oct 2023 07:28:00 GMT; Path=/\r
      \r

      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $cookies = $Request->cookies;
      return $Response->Json->send($cookies);
   },

   // @ test
   'test' => function ($response): bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 226\r
      \r
      [{"cookie1":"value1","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"},{"cookie2":"value2","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"},{"cookie3":"value3","Expires":"Wed, 21 Oct 2023 07:28:00 GMT","Path":"\/"}]
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug($response, $expected);
         return false;
      }

      return true;
   },
   'except' => function (): string {
      return 'Request not matched';
   }
];
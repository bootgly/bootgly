<?php
use Bootgly\ABI\Debugging\Data\Vars;
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
      // ...
      return <<<HTTP
      GET / HTTP/1.1\r
      Accept-Language: en-US, fr, es;q=0.8, de;q=0.5, pt-BR;q=0.2\r
      \r

      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $language = $Request->language;

      return $Response(content: $language);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 5\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      en-US
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

<?php
use Bootgly\Project;
use Bootgly\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange
   'separators' => [
      'header' => '@redirect'
   ],

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'Web/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      return $Response->redirect('https://docs.bootgly.com/', 302);
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/test/auth/1');
      return "GET /test/redirect/1 HTTP/1.0\r\n\r\n";
   },

   // @ assert
   'test' => function ($response) : bool {
      // ! Asserts
      // @ Assert response raw
      $expected = <<<HTML_RAW
      HTTP/1.1 302 Found\r
      Server: Bootgly\r
      Location: https://docs.bootgly.com/\r
      Content-Length: 0\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      
      HTML_RAW;

      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response raw not matched';
   }
];

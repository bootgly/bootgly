<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// @ Anonymous unknown Authentication implementation
$UnknownAuth = new class implements Authentication {};

return new Specification(
   Separator: new Separator(header: '@authenticate - unknown method'),

   request: function () {
      return "GET /test/auth/2 HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response) use ($UnknownAuth): Response {
      $Response(body: 'Unauthorized page!');
      return $Response->authenticate($UnknownAuth);
   },

   test: function ($response) {
      // ! Asserts
      // @ Assert that unknown auth method sets 401 but does NOT set WWW-Authenticate header
      $expected = <<<HTML_RAW
      HTTP/1.1 401 Unauthorized\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 18\r
      \r
      Unauthorized page!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched: unknown auth method should not set WWW-Authenticate header';
      }

      return true;
   }
);

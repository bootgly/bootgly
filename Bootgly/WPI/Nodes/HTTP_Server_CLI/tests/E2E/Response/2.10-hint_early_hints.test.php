<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes: two interim 103 responses, then the final 200.
//   The CRLF injected into the second Link value is stripped — the forged
//   fragment stays inline in the value, never a new header line.
$expected = "HTTP/1.1 103 Early Hints\r\n"
   . "Link: </app.css>; rel=preload; as=style\r\n"
   . "Link: </app.js>; rel=preload; as=scriptX-Forged: 1\r\n"
   . "\r\n"
   . "HTTP/1.1 103 Early Hints\r\n"
   . "Link: </font.woff2>; rel=preload; as=font\r\n"
   . "\r\n"
   . "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 7\r\n"
   . "\r\n"
   . "hinted!";

return new Specification(
   description: 'It should emit interim 103 Early Hints before the final response',

   request: function () {
      return "GET /hints HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // @ Repeatable: each call is one interim response; CRLF injection is stripped
      $Response->hint([
         '</app.css>; rel=preload; as=style',
         "</app.js>; rel=preload; as=script\r\nX-Forged: 1"
      ]);
      $Response->hint('</font.woff2>; rel=preload; as=font');

      return $Response(body: 'hinted!');
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return '103 Early Hints bytes not matched';
      }

      return true;
   }
);

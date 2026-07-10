<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should skip interim 103 for HTTP/1.0 clients',

   request: function () {
      return "GET /hints HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // @ Interim responses do not exist in HTTP/1.0 — must be a no-op
      $Response->hint('</app.css>; rel=preload; as=style');

      return $Response(body: 'plain');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, '103') || str_contains($response, 'Link:')) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'HTTP/1.0 response must not carry interim 103 bytes';
      }
      if (str_contains($response, 'HTTP/1.0 200 OK') === false || str_contains($response, 'plain') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Final HTTP/1.0 response not matched';
      }

      return true;
   }
);

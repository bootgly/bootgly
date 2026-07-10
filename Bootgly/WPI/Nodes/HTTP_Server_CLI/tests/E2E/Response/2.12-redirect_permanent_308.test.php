<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should redirect with 308 Permanent Redirect (status map regression)',

   request: function () {
      return "GET /old HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // @ 308 existed in redirect()'s allowlist but was missing from
      //   HTTP::RESPONSE_STATUS — code(308) raised an undefined-key error
      return $Response->redirect('/new', code: 308);
   },

   test: function ($response) {
      // @ Assert
      if (
         str_contains($response, 'HTTP/1.1 308 Permanent Redirect') === false
         || str_contains($response, "Location: /new\r\n") === false
      ) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return '308 redirect response not matched';
      }

      return true;
   }
);

<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'View rendering rejects a path-traversal view name with 403 (F-12 guard intact)',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // A traversal name must be rejected before any file resolution — the
      //   F-12 whitelist stays enforced through the view-engine expansion.
      return $Response->View->render('../../../etc/passwd');
   },

   test: function ($response) {
      if (str_contains($response, '403 Forbidden') === false) {
         return "Status is not 403 Forbidden: \n" . $response;
      }

      return true;
   }
);

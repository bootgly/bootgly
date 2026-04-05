<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'HTTP/1.1 Compliance (RFC 9112)'),
   description: 'It should reject HTTP/1.1 request without Host header with 400',

   request: function () {
      return "GET / HTTP/1.1\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      // @ Assert
      if ($response === '') {
         return true; // Connection was rejected (closed before response)
      }

      if (str_contains($response, '400 Bad Request')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have rejected with 400 Bad Request (missing Host header)';
   }
);

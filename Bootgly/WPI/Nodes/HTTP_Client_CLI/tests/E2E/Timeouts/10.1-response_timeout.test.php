<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Timeouts'),
   description: 'It should timeout when server takes too long to respond',

   response: function () {
      // @ Server delays 2 seconds before sending response
      return (function () {
         sleep(2);
         yield "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nLate";
      })();
   },

   request: function (HTTP_Client_CLI $Client): Response {
      $Client->timeout = 1; // 1 second timeout
      $response = $Client->request(method: 'GET', URI: '/slow');
      $Client->timeout = 30; // @ Restore default
      return $response;
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 0,
         description: "Response code is 0 on timeout: {$Response->code}"
      );

      yield assert(
         assertion: $Response->status === 'Timeout',
         description: "Response status is 'Timeout': {$Response->status}"
      );
   }
);

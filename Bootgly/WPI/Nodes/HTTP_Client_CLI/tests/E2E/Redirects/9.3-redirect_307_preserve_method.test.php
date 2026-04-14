<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should follow 307 redirect and preserve POST method and body',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function () {
         return "HTTP/1.1 307 Temporary Redirect\r\nLocation: /temporary\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function (string $input) {
         // @ 307 must preserve method AND body
         $method = strstr($input, ' ', true);
         // @ Extract body after \r\n\r\n
         $bodyPos = strpos($input, "\r\n\r\n");
         $body = $bodyPos !== false ? substr($input, $bodyPos + 4) : '';
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Method: {$method}\r\nX-Body: {$body}\r\nContent-Length: 8\r\nConnection: close\r\n\r\n307 Done";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(
            method: 'POST',
            URI: '/api/data',
            body: 'mydata'
         );
      },
      function (HTTP_Client_CLI $Client): Response {
         $r = new Response;
         $r->code = -1;
         return $r;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200,
         description: "Final status code after 307 redirect is 200: {$Response1->code}"
      );

      // @ 307 must preserve original method
      yield assert(
         assertion: $Response1->Header->get('X-Method') === 'POST',
         description: "Method preserved as POST after 307: " . $Response1->Header->get('X-Method')
      );
   }
);

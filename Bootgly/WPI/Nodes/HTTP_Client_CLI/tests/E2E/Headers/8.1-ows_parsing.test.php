<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Headers'),
   description: 'It should parse headers with OWS (optional whitespace)',

   // HTTP response with various OWS patterns
   // RFC 9110: field-name ":" OWS field-value OWS
   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type:text/plain\r\nX-No-Space:valueA\r\nX-Multi-Space:   valueB   \r\nX-Tab:\tvalueC\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/headers'
      );
   },

   // Test: headers should be parsed correctly regardless of OWS
   test: function (Response $Response) {
      // @ No space after colon
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: "No space after colon: Content-Type"
      );

      // @ No space after colon (custom header)
      yield assert(
         assertion: $Response->Header->get('X-No-Space') === 'valueA',
         description: "No space: X-No-Space = valueA"
      );

      // @ Multiple spaces (should be trimmed)
      yield assert(
         assertion: $Response->Header->get('X-Multi-Space') === 'valueB',
         description: "Multi-space trimmed: X-Multi-Space"
      );

      // @ Tab character (also valid OWS)
      yield assert(
         assertion: $Response->Header->get('X-Tab') === 'valueC',
         description: "Tab OWS: X-Tab = valueC"
      );

      // @ Case-insensitive lookup
      yield assert(
         assertion: $Response->Header->get('content-type') === 'text/plain',
         description: "Case-insensitive: content-type"
      );
   }
);

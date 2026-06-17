<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Header->type is a plain public property serialized into the response. A CRLF in its
// value must NOT be able to inject a new header line (HTTP response splitting). build()
// strips CR/LF at the single point it serializes the default media type, so the bytes
// after the CRLF stay part of the (garbled) Content-Type value — never a new header.

return new Specification(
   Separator: new Separator(header: '@send'),

   request: function () {
      return "GET /test/type/crlf/1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $Response->Header->type = "text/plain\r\nX-Injected: evil";

      return $Response->send('hi');
   },

   test: function ($response) {
      // The injected header line must NOT exist (no CRLF before X-Injected).
      if (str_contains($response, "\r\nX-Injected:")) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Response splitting: CRLF in Header->type injected a header line';
      }
      // Sanity: the stripped value is still emitted as one Content-Type line.
      if (! str_contains($response, "Content-Type: text/plainX-Injected: evil\r\n")) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'CRLF not stripped as expected from Header->type';
      }

      return true;
   }
);

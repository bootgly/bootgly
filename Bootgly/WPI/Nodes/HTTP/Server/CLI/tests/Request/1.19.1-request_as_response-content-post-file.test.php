<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separator.line' => true,
   'describe' => 'It should process request post file (1 file, 0 field)',
   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      Content-Type: multipart/form-data; boundary=X-INSOMNIA-BOUNDARY\r
      Accept: */*\r
      Content-Length: 204\r
      \r
      --X-INSOMNIA-BOUNDARY\r
      Content-Disposition: form-data; name="test1"; filename="payload1.txt"\r
      Content-Type: text/plain\r
      \r
      Test #1 - Testing upload of file in Bootgly CLI Server!\r
      --X-INSOMNIA-BOUNDARY--\r\n
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $Request->download();
      return $Response->Json->send($Request->files);
   },

   // @ test
   'test' => function ($response) {
      $parts = explode("\r\n\r\n", $response);
      $header = $parts[0];
      $body = json_decode($parts[1], true);
      unset($body['test1']['tmp_name']);
      $body = json_encode($body);
      // -
      $_ = strpos($header, "\r\nContent-Length: ");
      $contentLength = substr($header, $_ + 18, 10);

      $response = $header . "\r\n\r\n" . $body;
      // ---
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: $contentLength\r
      \r
      {"test1":{"name":"payload1.txt","size":55,"error":0,"type":"text\/plain"}}
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];

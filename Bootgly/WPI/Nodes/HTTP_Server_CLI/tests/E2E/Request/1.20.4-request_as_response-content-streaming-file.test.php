<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 0 files, 2 fields (multipart fields only)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-D\r
      Accept: */*\r
      Content-Length: 186\r
      \r
      --X-STREAM-BOUNDARY-D\r
      Content-Disposition: form-data; name="name"\r
      \r
      Bootgly\r
      --X-STREAM-BOUNDARY-D\r
      Content-Disposition: form-data; name="version"\r
      \r
      v0.10.0\r
      --X-STREAM-BOUNDARY-D--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
         'post' => $Request->post,
      ];

      return $Response->Json->send($result);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed';
      if ($body['streaming'] !== true) return 'streaming should be true';

      // @ Assert no files
      if (!empty($body['files'])) return 'files should be empty: ' . json_encode($body['files']);

      // @ Assert fields
      if (($body['post']['name'] ?? null) !== 'Bootgly') {
         return 'name mismatch: ' . json_encode($body['post']);
      }
      if (($body['post']['version'] ?? null) !== 'v0.10.0') {
         return 'version mismatch: ' . json_encode($body['post']);
      }

      return true;
   }
);

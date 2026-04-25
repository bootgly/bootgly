<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 1 file, 1 field (mixed parts)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-B\r
      Accept: */*\r
      Content-Length: 281\r
      \r
      --X-STREAM-BOUNDARY-B\r
      Content-Disposition: form-data; name="file1"; filename="streamed2.txt"\r
      Content-Type: text/plain\r
      \r
      Streaming upload test - one file with field!\r
      --X-STREAM-BOUNDARY-B\r
      Content-Disposition: form-data; name="field1"\r
      \r
      field_value_1\r
      --X-STREAM-BOUNDARY-B--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
         'post' => $Request->fields,
      ];

      if (isset($result['files']['file1']['tmp_name'])) {
         unset($result['files']['file1']['tmp_name']);
      }

      return $Response->Json->send($result);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed';
      if ($body['streaming'] !== true) return 'streaming should be true';

      // @ Assert file
      $file = $body['files']['file1'] ?? null;
      if ($file === null) return 'file1 not found: ' . json_encode($body);
      if ($file['name'] !== 'streamed2.txt') return 'name mismatch: ' . $file['name'];
      if ($file['size'] !== 44) return 'size mismatch: expected 44, got ' . $file['size'];
      if ($file['error'] !== 0) return 'file error: ' . $file['error'];
      if ($file['type'] !== 'text/plain') return 'type mismatch: ' . $file['type'];

      // @ Assert field
      if (($body['post']['field1'] ?? null) !== 'field_value_1') {
         return 'field1 mismatch: ' . json_encode($body['post']);
      }

      return true;
   }
);

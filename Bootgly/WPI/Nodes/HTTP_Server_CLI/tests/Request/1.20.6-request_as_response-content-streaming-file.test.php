<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 1 file, 2 fields (fields before file)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-F\r
      Accept: */*\r
      Content-Length: 361\r
      \r
      --X-STREAM-BOUNDARY-F\r
      Content-Disposition: form-data; name="category"\r
      \r
      streaming\r
      --X-STREAM-BOUNDARY-F\r
      Content-Disposition: form-data; name="priority"\r
      \r
      high\r
      --X-STREAM-BOUNDARY-F\r
      Content-Disposition: form-data; name="attachment"; filename="data.bin"\r
      Content-Type: application/octet-stream\r
      \r
      Binary payload simulation bytes!\r
      --X-STREAM-BOUNDARY-F--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
         'post' => $Request->post,
      ];

      if (isset($result['files']['attachment']['tmp_name'])) {
         unset($result['files']['attachment']['tmp_name']);
      }

      return $Response->Json->send($result);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed';
      if ($body['streaming'] !== true) return 'streaming should be true';

      // @ Assert file
      $file = $body['files']['attachment'] ?? null;
      if ($file === null) return 'attachment not found: ' . json_encode($body);
      if ($file['name'] !== 'data.bin') return 'name mismatch: ' . $file['name'];
      if ($file['size'] !== 32) return 'size mismatch: expected 32, got ' . $file['size'];
      if ($file['error'] !== 0) return 'file error: ' . $file['error'];
      if ($file['type'] !== 'application/octet-stream') return 'type mismatch: ' . $file['type'];

      // @ Assert fields
      if (($body['post']['category'] ?? null) !== 'streaming') {
         return 'category mismatch: ' . json_encode($body['post']);
      }
      if (($body['post']['priority'] ?? null) !== 'high') {
         return 'priority mismatch: ' . json_encode($body['post']);
      }

      return true;
   }
);

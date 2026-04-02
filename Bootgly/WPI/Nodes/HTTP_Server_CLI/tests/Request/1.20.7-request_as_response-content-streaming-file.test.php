<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 1 empty file (0 bytes content)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-G\r
      Accept: */*\r
      Content-Length: 146\r
      \r
      --X-STREAM-BOUNDARY-G\r
      Content-Disposition: form-data; name="empty"; filename="empty.txt"\r
      Content-Type: text/plain\r
      \r
      \r
      --X-STREAM-BOUNDARY-G--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
      ];

      if (isset($result['files']['empty']['tmp_name'])) {
         unset($result['files']['empty']['tmp_name']);
      }

      return $Response->Json->send($result);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed';
      if ($body['streaming'] !== true) return 'streaming should be true';

      // @ Assert empty file
      $file = $body['files']['empty'] ?? null;
      if ($file === null) return 'empty not found: ' . json_encode($body);
      if ($file['name'] !== 'empty.txt') return 'name mismatch: ' . $file['name'];
      if ($file['size'] !== 0) return 'size should be 0, got ' . $file['size'];
      if ($file['error'] !== 0) return 'file error: ' . $file['error'];
      if ($file['type'] !== 'text/plain') return 'type mismatch: ' . $file['type'];

      return true;
   }
);

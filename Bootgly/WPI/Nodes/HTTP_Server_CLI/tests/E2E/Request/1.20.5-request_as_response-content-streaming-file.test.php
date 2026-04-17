<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 3 files, 0 fields (multiple files)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-E\r
      Accept: */*\r
      Content-Length: 437\r
      \r
      --X-STREAM-BOUNDARY-E\r
      Content-Disposition: form-data; name="a"; filename="alpha.txt"\r
      Content-Type: text/plain\r
      \r
      Alpha file content!\r
      --X-STREAM-BOUNDARY-E\r
      Content-Disposition: form-data; name="b"; filename="bravo.txt"\r
      Content-Type: text/plain\r
      \r
      Bravo file content!\r
      --X-STREAM-BOUNDARY-E\r
      Content-Disposition: form-data; name="c"; filename="charlie.txt"\r
      Content-Type: text/plain\r
      \r
      Charlie file content!\r
      --X-STREAM-BOUNDARY-E--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
         'post' => $Request->post,
      ];

      foreach (['a', 'b', 'c'] as $key) {
         if (isset($result['files'][$key]['tmp_name'])) {
            unset($result['files'][$key]['tmp_name']);
         }
      }

      return $Response->Json->send($result);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed';
      if ($body['streaming'] !== true) return 'streaming should be true';

      // @ Assert 3 files
      $expected = [
         'a' => ['name' => 'alpha.txt', 'size' => 19, 'type' => 'text/plain'],
         'b' => ['name' => 'bravo.txt', 'size' => 19, 'type' => 'text/plain'],
         'c' => ['name' => 'charlie.txt', 'size' => 21, 'type' => 'text/plain'],
      ];

      foreach ($expected as $key => $exp) {
         $file = $body['files'][$key] ?? null;
         if ($file === null) return "$key not found: " . json_encode($body);
         if ($file['name'] !== $exp['name']) return "$key name mismatch: " . $file['name'];
         if ($file['size'] !== $exp['size']) return "$key size mismatch: expected {$exp['size']}, got " . $file['size'];
         if ($file['error'] !== 0) return "$key error: " . $file['error'];
         if ($file['type'] !== $exp['type']) return "$key type mismatch: " . $file['type'];
      }

      if (!empty($body['post'])) return 'post should be empty';

      return true;
   }
);

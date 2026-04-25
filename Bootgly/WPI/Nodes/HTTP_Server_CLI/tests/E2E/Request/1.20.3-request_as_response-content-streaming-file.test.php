<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should stream 2 files, 1 field (file-field-file order)',

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: bootgly-test/1.0\r
      Content-Type: multipart/form-data; boundary=X-STREAM-BOUNDARY-C\r
      Accept: */*\r
      Content-Length: 416\r
      \r
      --X-STREAM-BOUNDARY-C\r
      Content-Disposition: form-data; name="doc"; filename="report.txt"\r
      Content-Type: text/plain\r
      \r
      First streamed document content here.\r
      --X-STREAM-BOUNDARY-C\r
      Content-Disposition: form-data; name="title"\r
      \r
      My Report\r
      --X-STREAM-BOUNDARY-C\r
      Content-Disposition: form-data; name="image"; filename="photo.jpg"\r
      Content-Type: image/jpeg\r
      \r
      Fake JPEG binary data for test!\r
      --X-STREAM-BOUNDARY-C--\r\n
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $result = [
         'streaming' => $Request->Body->streaming,
         'files' => $Request->files,
         'post' => $Request->fields,
      ];

      foreach (['doc', 'image'] as $key) {
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

      // @ Assert file 1 (doc)
      $doc = $body['files']['doc'] ?? null;
      if ($doc === null) return 'doc not found: ' . json_encode($body);
      if ($doc['name'] !== 'report.txt') return 'doc name mismatch: ' . $doc['name'];
      if ($doc['size'] !== 37) return 'doc size mismatch: expected 37, got ' . $doc['size'];
      if ($doc['error'] !== 0) return 'doc error: ' . $doc['error'];
      if ($doc['type'] !== 'text/plain') return 'doc type mismatch: ' . $doc['type'];

      // @ Assert file 2 (image)
      $img = $body['files']['image'] ?? null;
      if ($img === null) return 'image not found: ' . json_encode($body);
      if ($img['name'] !== 'photo.jpg') return 'image name mismatch: ' . $img['name'];
      if ($img['size'] !== 31) return 'image size mismatch: expected 31, got ' . $img['size'];
      if ($img['error'] !== 0) return 'image error: ' . $img['error'];
      if ($img['type'] !== 'image/jpeg') return 'image type mismatch: ' . $img['type'];

      // @ Assert field
      if (($body['post']['title'] ?? null) !== 'My Report') {
         return 'title mismatch: ' . json_encode($body['post']);
      }

      return true;
   }
);

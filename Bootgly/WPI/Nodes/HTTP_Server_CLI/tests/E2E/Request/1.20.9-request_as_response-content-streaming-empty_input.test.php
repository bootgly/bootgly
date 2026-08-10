<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Regression (DEC-1): an empty file input (`filename=""`, what a browser sends
// when the user picks no file) must land in `$Request->files` as the no-file
// record PHP's rfc1867 parser produces — error UPLOAD_ERR_NO_FILE (4), empty
// name/tmp_name/type — with NO temp file created, while a real file part in
// the same body still uploads normally.

return new Specification(
   description: 'It should report an empty file input as no-file beside a real upload',

   request: function () {
      $boundary = 'X-STREAM-BOUNDARY-I';
      $content = 'Real file content!';

      $body =
         "--{$boundary}\r\n" .
         "Content-Disposition: form-data; name=\"avatar\"; filename=\"\"\r\n" .
         "Content-Type: application/octet-stream\r\n" .
         "\r\n" .
         "\r\n" .
         "--{$boundary}\r\n" .
         "Content-Disposition: form-data; name=\"doc\"; filename=\"real.txt\"\r\n" .
         "Content-Type: text/plain\r\n" .
         "\r\n" .
         "{$content}\r\n" .
         "--{$boundary}--\r\n";
      $length = strlen($body);

      return
         "POST / HTTP/1.1\r\n" .
         "Host: lab.bootgly.com:8080\r\n" .
         "User-Agent: bootgly-test/1.0\r\n" .
         "Content-Type: multipart/form-data; boundary={$boundary}\r\n" .
         "Accept: */*\r\n" .
         "Content-Length: {$length}\r\n" .
         "\r\n" .
         $body;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->download();

      $files = $Request->files;
      $doc = is_array($files['doc'] ?? null) ? $files['doc'] : [];
      $tmp = (string) ($doc['tmp_name'] ?? '');

      return $Response->JSON->send([
         'streaming' => $Request->Body->streaming,
         'avatar' => $files['avatar'] ?? null,
         'doc_name' => $doc['name'] ?? null,
         'doc_size' => $doc['size'] ?? null,
         'doc_error' => $doc['error'] ?? null,
         'doc_type' => $doc['type'] ?? null,
         'doc_on_disk' => $tmp !== '' && is_file($tmp),
      ]);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed: ' . $response;
      if ($body['streaming'] !== true) return 'streaming should be true: ' . json_encode($body);

      // @ Assert the no-file record — $_FILES parity, byte for byte
      $expected = ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => 4, 'type' => ''];
      if ($body['avatar'] !== $expected) {
         return 'no-file record mismatch — observed: ' . json_encode($body['avatar']);
      }

      // @ Assert the real upload beside it is untouched
      if ($body['doc_name'] !== 'real.txt') return 'doc name mismatch: ' . json_encode($body);
      if ($body['doc_size'] !== 18) return 'doc size mismatch: ' . json_encode($body);
      if ($body['doc_error'] !== 0) return 'doc error mismatch: ' . json_encode($body);
      if ($body['doc_type'] !== 'text/plain') return 'doc type mismatch: ' . json_encode($body);
      if ($body['doc_on_disk'] !== true) return 'doc temp file missing: ' . json_encode($body);

      return true;
   }
);

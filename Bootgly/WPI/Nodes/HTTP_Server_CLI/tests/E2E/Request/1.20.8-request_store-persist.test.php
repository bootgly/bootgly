<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\ABI\Resources\Storage;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should persist an uploaded file into a Storage disk and reclaim the temp',
   Separator: new Separator(line: true),

   request: function () {
      $boundary = 'X-STORE-BOUNDARY';
      $content = 'Persist me into a Storage disk!';

      $body =
         "--{$boundary}\r\n" .
         "Content-Disposition: form-data; name=\"file1\"; filename=\"persist.txt\"\r\n" .
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

      // ! A disk to persist into — local 'scratch' rooted under storage/tests/store
      $Storage = new Storage([
         'disks' => [
            'scratch' => ['driver' => 'local', 'root' => BOOTGLY_STORAGE_DIR . 'tests/store'],
         ],
      ]);
      $Disk = $Storage->open('scratch');

      // @ Move the uploaded temp file into the disk
      $tmp = $Request->files['file1']['tmp_name'] ?? '';
      $stored = $Request->store('file1', 'persisted/persist.txt', $Disk);

      // @ Read it back through the disk to prove the bytes landed, then clean up
      $md5 = null;
      if ($stored !== false) {
         $sink = fopen('php://temp', 'r+');
         if ($Disk->read($stored, $sink) === true) {
            rewind($sink);
            $md5 = md5((string) stream_get_contents($sink));
         }
         $Disk->delete($stored);
      }

      return $Response->JSON->send([
         'stored'    => $stored,
         'error'     => $Disk->error,
         'md5'       => $md5,
         'temp_gone' => $tmp !== '' && is_file($tmp) === false,
         'missing'   => $Request->store('nope', 'x.txt', $Disk),
      ]);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed: ' . $response;
      if ($body['stored'] !== 'persisted/persist.txt') return 'stored mismatch: ' . json_encode($body);
      if ($body['md5'] !== md5('Persist me into a Storage disk!')) return 'md5 mismatch: ' . json_encode($body);
      if ($body['temp_gone'] !== true) return 'temp not reclaimed: ' . json_encode($body);
      if ($body['missing'] !== false) return 'missing key should be false: ' . json_encode($body);

      return true;
   }
);

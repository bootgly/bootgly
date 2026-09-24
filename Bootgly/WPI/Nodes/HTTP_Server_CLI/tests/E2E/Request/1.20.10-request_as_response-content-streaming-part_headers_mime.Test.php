<?php

use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\MIME;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// Regression (M6 + DEC-4): an upload is validated by the bytes the server
// received, not by the part's declared Content-Type — and that declared type
// reaches `$Request->files` whatever line order the part's headers came in.
//
// - `shell` carries PHP source declared `image/png`: the MIME rule refuses it;
// - `avatar` carries a real PNG declared `text/plain`, with its Content-Type
//   line BEFORE its Content-Disposition: the rule accepts it, and its record
//   keeps `type: text/plain` (the client's hint, recorded as sent).

return new Test(
   description: 'It should validate uploads by content and keep the part type in any header order',

   request: function () {
      $boundary = 'X-STREAM-BOUNDARY-M6';
      $PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

      $body =
         "--{$boundary}\r\n" .
         "Content-Disposition: form-data; name=\"shell\"; filename=\"shell.png\"\r\n" .
         "Content-Type: image/png\r\n" .
         "\r\n" .
         "<?php system(\$_GET['c']);\r\n" .
         "--{$boundary}\r\n" .
         "Content-Type: text/plain\r\n" .
         "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n" .
         "\r\n" .
         "{$PNG}\r\n" .
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
      $Validation = new Validation($files, [
         'shell' => new MIME('image/png'),
         'avatar' => new MIME('image/png'),
      ]);
      $avatar = is_array($files['avatar'] ?? null) ? $files['avatar'] : [];
      $shell = is_array($files['shell'] ?? null) ? $files['shell'] : [];

      return $Response->JSON->send([
         'invalid' => array_keys($Validation->errors),
         'avatar_type' => $avatar['type'] ?? null,
         'avatar_error' => $avatar['error'] ?? null,
         'shell_type' => $shell['type'] ?? null,
         'shell_error' => $shell['error'] ?? null,
      ]);
   },

   test: function ($response) {
      $body = json_decode(explode("\r\n\r\n", $response)[1], true);

      if ($body === null) return 'JSON decode failed: ' . $response;

      // @ Both parts landed as uploads — the type each one declared, as sent
      if ($body['shell_error'] !== 0 || $body['avatar_error'] !== 0) {
         return 'upload error: ' . json_encode($body);
      }
      if ($body['shell_type'] !== 'image/png') return 'shell type mismatch: ' . json_encode($body);
      if ($body['avatar_type'] !== 'text/plain') {
         return 'avatar type lost (Content-Type before Content-Disposition): ' . json_encode($body);
      }

      // @ Only the PHP bytes fail the image rule
      if ($body['invalid'] !== ['shell']) {
         return 'MIME verdicts mismatch — expected only `shell` invalid: ' . json_encode($body);
      }

      return true;
   }
);

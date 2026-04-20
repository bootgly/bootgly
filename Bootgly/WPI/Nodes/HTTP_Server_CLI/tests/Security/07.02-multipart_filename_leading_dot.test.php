<?php

use function json_decode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — multipart upload must not preserve leading-dot filename in $_FILES.
 */

return new Specification(
   description: 'Multipart uploaded filename must not preserve leading dot',
   Separator: new Separator(line: true),

   request: function (): string {
      $boundary = '---------------------------735323031399963166993862150';
      $body = ''
         . "--{$boundary}\r\n"
         . "Content-Disposition: form-data; name=\"file\"; filename=\".htaccess\"\r\n"
         . "Content-Type: text/plain\r\n"
         . "\r\n"
         . "deny from all\r\n"
         . "--{$boundary}--\r\n";

      return "POST /upload-hidden HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
         . "Content-Length: " . strlen($body) . "\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $body;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/upload-hidden', function (Request $Request, Response $Response) {
         $file = $Request->files['file'] ?? null;
         $name = is_array($file) ? (string) ($file['name'] ?? '') : '';

         return $Response->Json->send([
            'name' => $name,
            'error' => is_array($file) ? (int) ($file['error'] ?? -1) : -1,
         ]);
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response): bool|string {
      if ($response === '') {
         return 'No response from server.';
      }

      if (! str_contains($response, '200 OK')) {
         return 'Expected 200 OK for /upload-hidden. Response (truncated): '
            . substr($response, 0, 300);
      }

      $parts = explode("\r\n\r\n", $response, 2);
      $json = $parts[1] ?? '';
      $decoded = json_decode($json, true);

      if (! is_array($decoded)) {
         return 'Server did not return JSON payload for upload result. '
            . 'Response (truncated): ' . substr($response, 0, 300);
      }

      $name = (string) ($decoded['name'] ?? '');
      if ($name === '') {
         return 'Upload did not produce a filename in $_FILES.';
      }

      if (str_starts_with($name, '.')) {
         return 'Vulnerability reproduced: multipart filename preserves a leading dot '
            . '(e.g. .htaccess). Expected sanitized filename without leading dot.';
      }

      return true;
   }
);

<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression H7 — live multipart lifecycle ownership.
 *
 * Five real-socket scenarios share one worker:
 *   1. a complete ordinary upload is the cleanup control;
 *   2. a complete upload whose response is deferred must also be reclaimed;
 *   3. rejection after a file part must roll the file/reservation back; and
 *   4. peer disconnect during a partial file must abort it immediately; and
 *   5. peer disconnect after the terminal boundary but before Content-Length
 *      must keep decoder ownership and reclaim the completed part.
 *
 * Every scenario snapshots the download directory and shared aggregate
 * counter. Test-owned residue is removed before the assertion returns.
 */

$probe = [
   'error' => '',
   'prime_response' => '',
   'normal' => [],
   'deferred' => [],
   'rejected' => [],
   'disconnected' => [],
   'terminal_disconnected' => [],
   'final_cleanup' => [],
];

return new Specification(
   description: 'Multipart temps and reservations must be reclaimed on reject, disconnect, and deferred completion',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $directory = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';

      $Scan = static function () use ($directory): array {
         $paths = glob($directory . '*');
         if ($paths === false) {
            return [];
         }

         return array_values(array_filter($paths, is_file(...)));
      };

      $Inspect = static function (array $paths): array {
         $evidence = [];
         foreach ($paths as $path) {
            $content = @file_get_contents($path);
            $evidence[] = [
               'name' => basename($path),
               'size' => is_file($path) ? (int) @filesize($path) : null,
               'prefix' => is_string($content) ? substr($content, 0, 48) : null,
            ];
         }

         return $evidence;
      };

      $Cleanup = static function (array $before) use ($Scan): void {
         foreach (array_diff($Scan(), $before) as $path) {
            if (is_file($path)) {
               @unlink($path);
            }
         }
         Downloads::reconcile();
      };

      $Write = static function ($socket, string $bytes): bool {
         $offset = 0;
         $length = strlen($bytes);
         while ($offset < $length) {
            $written = @fwrite($socket, substr($bytes, $offset));
            if (! is_int($written) || $written <= 0) {
               return false;
            }
            $offset += $written;
         }

         return true;
      };

      $Send = static function (string $hostPort, string $request) use ($Write): string {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return "CONNECT-ERROR:{$errorNumber}:{$errorMessage}";
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);
         $Write($socket, $request);

         $response = '';
         while (! @feof($socket)) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }
            $response .= $chunk;
         }

         @fclose($socket);

         return $response;
      };

      $Build = static function (
         string $path,
         string $boundary,
         string $marker,
         int $testIndex
      ): string {
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . $marker . "\r\n"
            . "--{$boundary}--\r\n";

         return "POST {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
      };

      $initialFiles = $Scan();
      $initialBaseline = Downloads::peek();

      try {
         $probe['prime_response'] = $Send(
            $hostPort,
            "GET /h7-lifecycle-prime HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Connection: close\r\n\r\n"
         );

         // # Ordinary completion control
         $before = $Scan();
         $baseline = Downloads::peek();
         $normalResponse = $Send($hostPort, $Build(
            '/h7-lifecycle-normal',
            'Bootgly-H7-Normal-Boundary',
            'H7-NORMAL-CONTROL-' . str_repeat('N', 4096),
            $testIndex
         ));
         usleep(100_000);
         $normalFiles = array_values(array_diff($Scan(), $before));
         $probe['normal'] = [
            'response' => substr($normalResponse, 0, 300),
            'files' => $Inspect($normalFiles),
            'reservation_delta' => Downloads::peek() - $baseline,
         ];
         $Cleanup($before);

         // # Deferred successful response
         $before = $Scan();
         $baseline = Downloads::peek();
         $deferredResponse = $Send($hostPort, $Build(
            '/h7-lifecycle-deferred',
            'Bootgly-H7-Deferred-Boundary',
            'H7-DEFERRED-LEAK-' . str_repeat('D', 4096),
            $testIndex
         ));
         usleep(150_000);
         $deferredFiles = array_values(array_diff($Scan(), $before));
         $probe['deferred'] = [
            'response' => substr($deferredResponse, 0, 300),
            'files' => $Inspect($deferredFiles),
            'reservation_delta' => Downloads::peek() - $baseline,
         ];
         $Cleanup($before);

         // # Rejection after a completed file part
         $before = $Scan();
         $baseline = Downloads::peek();
         $boundary = 'Bootgly-H7-Reject-Boundary';
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7-reject.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . 'H7-REJECT-LEAK-' . str_repeat('R', 8192) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"oversized\"\r\n\r\n"
            . str_repeat('X', Request::$maxMultipartFieldSize + 1) . "\r\n"
            . "--{$boundary}--\r\n";
         $rejectRequest = "POST /h7-lifecycle-reject HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
         $rejectResponse = $Send($hostPort, $rejectRequest);
         usleep(150_000);
         $rejectedFiles = array_values(array_diff($Scan(), $before));
         $probe['rejected'] = [
            'response' => substr($rejectResponse, 0, 300),
            'files' => $Inspect($rejectedFiles),
            'reservation_delta' => Downloads::peek() - $baseline,
         ];
         $Cleanup($before);

         // # Abrupt peer disconnect during a file part
         $before = $Scan();
         $baseline = Downloads::peek();
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            $probe['error'] = "Disconnect probe could not connect: {$errorNumber} {$errorMessage}";
         }
         else {
            stream_set_blocking($socket, true);
            $boundary = 'Bootgly-H7-Disconnect-Boundary';
            $prefix = "--{$boundary}\r\n"
               . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7-disconnect.bin\"\r\n"
               . "Content-Type: application/octet-stream\r\n\r\n"
               . 'H7-DISCONNECT-LEAK-';
            $declaredLength = strlen($prefix) + 131072;
            $head = "POST /h7-lifecycle-disconnect HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . "Content-Length: {$declaredLength}\r\n"
               . "\r\n"
               . $prefix;

            if (! $Write($socket, $head)) {
               $probe['error'] = 'Disconnect probe could not write the request prefix.';
            }
            usleep(150_000);
            if ($probe['error'] === '' && ! $Write($socket, str_repeat('Q', 32768))) {
               $probe['error'] = 'Disconnect probe could not write the file chunk.';
            }
            usleep(200_000);

            $duringFiles = array_values(array_diff($Scan(), $before));
            $duringDelta = Downloads::peek() - $baseline;
            @fclose($socket);
            usleep(300_000);
            $afterFiles = array_values(array_diff($Scan(), $before));
            $probe['disconnected'] = [
               'during_files' => $Inspect($duringFiles),
               'during_reservation_delta' => $duringDelta,
               'after_files' => $Inspect($afterFiles),
               'after_reservation_delta' => Downloads::peek() - $baseline,
            ];
         }
         $Cleanup($before);

         // # Terminal boundary received, declared multipart epilogue missing
         $before = $Scan();
         $baseline = Downloads::peek();
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            $probe['error'] = "Terminal-disconnect probe could not connect: {$errorNumber} {$errorMessage}";
         }
         else {
            stream_set_blocking($socket, true);
            $boundary = 'Bootgly-H7-Terminal-Disconnect-Boundary';
            $body = "--{$boundary}\r\n"
               . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7-terminal.bin\"\r\n"
               . "Content-Type: application/octet-stream\r\n\r\n"
               . 'H7-TERMINAL-DISCONNECT-' . str_repeat('T', 16384) . "\r\n"
               . "--{$boundary}--\r\n";
            $declaredLength = strlen($body) + 65536;
            $head = "POST /h7-lifecycle-terminal-disconnect HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . "Content-Length: {$declaredLength}\r\n\r\n";

            if (! $Write($socket, $head)) {
               $probe['error'] = 'Terminal-disconnect probe could not write the request head.';
            }
            usleep(100_000);
            if ($probe['error'] === '' && ! $Write($socket, $body)) {
               $probe['error'] = 'Terminal-disconnect probe could not write the multipart body.';
            }
            usleep(250_000);

            $duringFiles = array_values(array_diff($Scan(), $before));
            $duringDelta = Downloads::peek() - $baseline;
            @fclose($socket);
            usleep(300_000);
            $afterFiles = array_values(array_diff($Scan(), $before));
            $probe['terminal_disconnected'] = [
               'during_files' => $Inspect($duringFiles),
               'during_reservation_delta' => $duringDelta,
               'after_files' => $Inspect($afterFiles),
               'after_reservation_delta' => Downloads::peek() - $baseline,
            ];
         }
         $Cleanup($before);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $Cleanup($initialFiles);
         $probe['final_cleanup'] = [
            'files' => $Inspect(array_values(array_diff($Scan(), $initialFiles))),
            'reservation_delta' => Downloads::peek() - $initialBaseline,
         ];
      }

      return "GET /h7-lifecycle-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h7-lifecycle-prime', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H7-LIFECYCLE-PRIME-OK');
      }, GET);

      yield $Router->route('/h7-lifecycle-normal', function (Request $Request, Response $Response) {
         $file = $Request->download('upload');
         $size = is_array($file) ? (int) ($file['size'] ?? -1) : -1;

         return $Response(code: 200, body: "H7-NORMAL-OK:{$size}");
      }, POST);

      yield $Router->route('/h7-lifecycle-deferred', function (Request $Request, Response $Response) {
         $file = $Request->download('upload');
         $size = is_array($file) ? (int) ($file['size'] ?? -1) : -1;

         return $Response->defer(function (Response $Response) use ($size) {
            $Response->wait();
            $Response(body: "H7-DEFERRED-OK:{$size}");
         });
      }, POST);

      yield $Router->route('/h7-lifecycle-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H7-LIFECYCLE-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'H7-LIFECYCLE-HARNESS-OK')) {
         return 'H7 lifecycle harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         Vars::$labels = ['H7 lifecycle setup'];
         dump(json_encode($probe));
         return $probe['error'];
      }
      if (! str_contains($probe['prime_response'], 'H7-LIFECYCLE-PRIME-OK')) {
         return 'H7 lifecycle primer did not install the test handler.';
      }
      if (
         ($probe['final_cleanup']['files'] ?? []) !== []
         || ($probe['final_cleanup']['reservation_delta'] ?? null) !== 0
      ) {
         Vars::$labels = ['H7 PoC final cleanup'];
         dump(json_encode($probe));
         return 'The H7 PoC did not restore its initial temp-file and reservation baseline.';
      }

      $normal = $probe['normal'];
      if (
         ! str_contains((string) ($normal['response'] ?? ''), 'H7-NORMAL-OK:')
         || ($normal['files'] ?? []) !== []
         || ($normal['reservation_delta'] ?? null) !== 0
      ) {
         Vars::$labels = ['H7 normal cleanup control'];
         dump(json_encode($probe));
         return 'Ordinary multipart completion control did not clean its temp file and reservation.';
      }

      $leaks = [];
      $deferred = $probe['deferred'];
      if (
         ($deferred['files'] ?? []) !== []
         || (int) ($deferred['reservation_delta'] ?? 0) > 0
      ) {
         $leaks[] = 'deferred';
      }

      $rejected = $probe['rejected'];
      if (
         ($rejected['files'] ?? []) !== []
         || (int) ($rejected['reservation_delta'] ?? 0) > 0
      ) {
         $leaks[] = 'reject';
      }

      $disconnected = $probe['disconnected'];
      if (
         ($disconnected['during_files'] ?? []) === []
         || (int) ($disconnected['during_reservation_delta'] ?? 0) <= 0
      ) {
         Vars::$labels = ['H7 disconnect setup evidence'];
         dump(json_encode($probe));
         return 'Disconnect probe did not materialize a partial temp upload before close.';
      }
      if (
         ($disconnected['after_files'] ?? []) !== []
         || (int) ($disconnected['after_reservation_delta'] ?? 0) > 0
      ) {
         $leaks[] = 'disconnect';
      }

      $terminalDisconnected = $probe['terminal_disconnected'];
      if (
         ($terminalDisconnected['during_files'] ?? []) === []
         || (int) ($terminalDisconnected['during_reservation_delta'] ?? 0) <= 0
      ) {
         Vars::$labels = ['H7 terminal-disconnect setup evidence'];
         dump(json_encode($probe));
         return 'Terminal-disconnect probe did not retain decoder-owned upload state.';
      }
      if (
         ($terminalDisconnected['after_files'] ?? []) !== []
         || (int) ($terminalDisconnected['after_reservation_delta'] ?? 0) > 0
      ) {
         $leaks[] = 'terminal-disconnect';
      }

      if ($leaks !== []) {
         Vars::$labels = ['H7 lifecycle evidence'];
         dump(json_encode($probe));
         $summary = [
            'normal_files' => count($normal['files'] ?? []),
            'normal_delta' => $normal['reservation_delta'] ?? null,
            'deferred_files' => count($deferred['files'] ?? []),
            'deferred_delta' => $deferred['reservation_delta'] ?? null,
            'reject_413' => str_contains((string) ($rejected['response'] ?? ''), '413 Request Entity Too Large'),
            'reject_files' => count($rejected['files'] ?? []),
            'reject_delta' => $rejected['reservation_delta'] ?? null,
            'disconnect_during_files' => count($disconnected['during_files'] ?? []),
            'disconnect_during_delta' => $disconnected['during_reservation_delta'] ?? null,
            'disconnect_after_files' => count($disconnected['after_files'] ?? []),
            'disconnect_after_delta' => $disconnected['after_reservation_delta'] ?? null,
            'terminal_disconnect_during_files' => count($terminalDisconnected['during_files'] ?? []),
            'terminal_disconnect_during_delta' => $terminalDisconnected['during_reservation_delta'] ?? null,
            'terminal_disconnect_after_files' => count($terminalDisconnected['after_files'] ?? []),
            'terminal_disconnect_after_delta' => $terminalDisconnected['after_reservation_delta'] ?? null,
         ];
         return 'H7 reproduced: multipart cleanup leaked on ' . implode(', ', $leaks)
            . '; evidence=' . json_encode($summary);
      }

      return true;
   }
);

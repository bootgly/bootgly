<?php

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
/**
 * Security regression H7 — initial multipart bytes and completion accounting.
 *
 * The request head and a body prefix are decoded first. The remaining body,
 * including the terminal boundary, reaches the per-connection streaming
 * decoder in a second call. A correct decoder must count both calls, return
 * Complete, and leave Body->downloaded equal to Content-Length. Historically
 * feed() retained the prefix without adding it to Decoder_Downloading's wire
 * counter, producing Incomplete after the terminal boundary had already made
 * Body->waiting false and materialized a temp upload.
 */

$probe = [
   'error' => '',
   'head_state' => null,
   'tail_state' => null,
   'body_length' => null,
   'initial_length' => null,
   'tail_length' => null,
   'downloaded' => null,
   'waiting' => null,
   'has_files' => null,
   'temporary_exists' => null,
   'counter_baseline' => null,
   'reservation_delta' => null,
   'counter_after_cleanup' => null,
];

return new Specification(
   description: 'Multipart initial feed bytes must count toward split-body completion',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate the temporary stream surrogate.';
         return "GET /h7-accounting-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      }

      $WPI = WPI;
      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldWPIRequest = $WPI->Request;
      $OldWPIResponse = $WPI->Response;
      $OldWPIRouter = $WPI->Router;

      $directory = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
      $Scan = static function () use ($directory): array {
         $paths = glob($directory . '*');
         if ($paths === false) {
            return [];
         }

         return array_values(array_filter($paths, is_file(...)));
      };
      $beforeFiles = $Scan();
      $baseline = Downloads::peek();
      $probe['counter_baseline'] = $baseline;

      try {
         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Connection->Socket = $socket;
         $Connection->timers = [];
         $Connection->handshakeTimer = 0;
         $Connection->handshaking = false;
         $Connection->writes = 0;
         $Connection->ip = '127.0.0.1';
         $Connection->port = 12345;
         $Connection->encrypted = false;

         $Package = new class($Connection) extends TCPPackages {
            public string $rejectRaw = '';

            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;
               $this->cache = true;
               $this->changed = true;
               $this->input = '';
               $this->output = '';
               $this->callbacks = [&$this->input];
               $this->expired = false;
               $this->downloading = [];
               $this->uploading = [];
               $this->closeAfterWrite = false;
            }

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejectRaw = $raw;
            }
         };

         $Request = new Request;
         Server::$Request = $Request;
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;
         $WPI->Request = Server::$Request;
         $WPI->Response = Server::$Response;
         $WPI->Router = Server::$Router;

         $boundary = 'Bootgly-H7-Accounting-Boundary';
         $fileData = str_repeat('A', 16384);
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . $fileData . "\r\n"
            . "--{$boundary}--\r\n";

         $bodyLength = strlen($body);
         $initialLength = 8213;
         $initial = substr($body, 0, $initialLength);
         $tail = substr($body, $initialLength);
         $raw = "POST /h7-accounting HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: {$bodyLength}\r\n"
            . "\r\n"
            . $initial;

         $HeadState = $Request->decode($Package, $raw, strlen($raw));
         $StreamingDecoder = $Package->Decoder;
         if (! $StreamingDecoder instanceof Decoder_Downloading) {
            $probe['error'] = 'Request did not install Decoder_Downloading for the split multipart body.';
         }
         else {
            $pipeline = "GET /must-remain-pipelined HTTP/1.1\r\nHost: localhost\r\n\r\n";
            $tailInput = $tail . $pipeline;
            $TailState = $StreamingDecoder->decode($Package, $tailInput, strlen($tailInput));
            $file = $Request->download('upload');
            $tmp = is_array($file) && is_string($file['tmp_name'] ?? null)
               ? $file['tmp_name']
               : '';

            $probe['head_state'] = $HeadState->name;
            $probe['tail_state'] = $TailState->name;
            $probe['body_length'] = $bodyLength;
            $probe['initial_length'] = $initialLength;
            $probe['tail_length'] = strlen($tail);
            $probe['tail_input_length'] = strlen($tailInput);
            $probe['tail_consumed'] = $Package->consumed;
            $probe['downloaded'] = $Request->Body->downloaded;
            $probe['waiting'] = $Request->Body->waiting;
            $probe['has_files'] = $Request->hasFiles;
            $probe['temporary_exists'] = $tmp !== '' && is_file($tmp);
            $probe['reservation_delta'] = Downloads::peek() - $baseline;

            if ($Request->hasFiles) {
               $Request->clean();
            }
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach (array_diff($Scan(), $beforeFiles) as $path) {
            if (is_file($path)) {
               @unlink($path);
            }
            Downloads::discard($path);
         }
         Downloads::reconcile();
         $probe['counter_after_cleanup'] = Downloads::peek();

         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;
         $WPI->Request = $OldWPIRequest;
         $WPI->Response = $OldWPIResponse;
         $WPI->Router = $OldWPIRouter;

         if (is_resource($socket)) {
            @fclose($socket);
         }
      }

      return "GET /h7-accounting-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h7-accounting-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H7-ACCOUNTING-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'H7-ACCOUNTING-HARNESS-OK')) {
         return 'H7 accounting harness did not receive its control response.';
      }

      if ($probe['error'] !== '') {
         Vars::$labels = ['H7 accounting probe'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if (
         $probe['tail_state'] !== States::Complete->name
         || $probe['downloaded'] !== $probe['body_length']
         || $probe['waiting'] !== false
         || $probe['tail_consumed'] !== $probe['tail_length']
      ) {
         Vars::$labels = ['H7 accounting evidence'];
         dump(json_encode($probe));
         $summary = [
            'tail_state' => $probe['tail_state'],
            'downloaded' => $probe['downloaded'],
            'content_length' => $probe['body_length'],
            'waiting' => $probe['waiting'],
            'tail_consumed' => $probe['tail_consumed'],
            'expected_current_call_consumed' => $probe['tail_length'],
            'pipeline_bytes_preserved' => $probe['tail_input_length'] - $probe['tail_consumed'],
            'temporary_exists' => $probe['temporary_exists'],
            'reservation_delta' => $probe['reservation_delta'],
            'counter_after_cleanup' => $probe['counter_after_cleanup'],
         ];
         return 'H7 reproduced: initial multipart bytes were omitted from completion accounting; evidence='
            . json_encode($summary);
      }

      if ($probe['counter_after_cleanup'] !== $probe['counter_baseline']) {
         Vars::$labels = ['H7 accounting cleanup evidence'];
         dump(json_encode($probe));
         return 'The PoC cleanup did not restore the Downloads counter.';
      }

      return true;
   }
);

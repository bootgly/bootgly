<?php

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression H7 — timeout and dropped-decoder cleanup.
 *
 * Reflection backdates only the absolute body deadline so the timeout path is
 * deterministic. A second partial decoder is then detached and destroyed.
 * Both paths must unlink their owned temporary file and release its aggregate
 * Downloads reservation without redispatching late request-shaped bytes.
 */

$probe = [
   'error' => '',
   'timeout_state' => '',
   'timeout_rejection' => '',
   'timeout_consumed' => -1,
   'timeout_before_files' => 0,
   'timeout_before_delta' => 0,
   'timeout_after_files' => 0,
   'timeout_after_delta' => 0,
   'destructor_before_files' => 0,
   'destructor_before_delta' => 0,
   'destructor_after_files' => 0,
   'destructor_after_delta' => 0,
   'fallback_called' => false,
];

return new Specification(
   description: 'Expired or dropped multipart decoders must release owned temps and reservations',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $OldDecoder = Server::$Decoder;

      $directory = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
      $Scan = static function () use ($directory): array {
         $paths = glob($directory . '*');
         if ($paths === false) {
            return [];
         }

         return array_values(array_filter($paths, is_file(...)));
      };
      $Build = static function (): TCPPackages {
         return new class extends TCPPackages {
            public string $rejection = '';

            public function __construct ()
            {
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
               $this->rejection = $raw;
            }
         };
      };
      $Partial = static function (string $boundary, string $marker): string {
         return "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"h7.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $marker . str_repeat('P', 16384);
      };

      $beforeFiles = $Scan();
      $baseline = Downloads::peek();

      try {
         $Fallback = new class extends Decoders {
            public bool $called = false;

            public function decode (
               Bootgly\WPI\Endpoints\Servers\Packages $Package,
               string $buffer,
               int $size
            ): States {
               $this->called = true;
               $Package->consumed = $size;
               return States::Complete;
            }
         };
         Server::$Decoder = $Fallback;

         // # Absolute timeout
         $boundary = 'Bootgly-H7-Timeout-Boundary';
         $partial = $Partial($boundary, 'H7-TIMEOUT-OWNED-');
         $Request = new Request;
         $Request->Body->waiting = true;
         $Request->Body->streaming = true;
         $Request->Body->length = strlen($partial) + 65536;
         $WPI->Request = $Request;

         $Package = $Build();
         $Decoder = new Decoder_Downloading;
         $Decoder->Request = $Request;
         $Decoder->init($boundary);
         $Package->Decoder = $Decoder;
         $Decoder->decode($Package, $partial, strlen($partial));

         $probe['timeout_before_files'] = count(array_diff($Scan(), $beforeFiles));
         $probe['timeout_before_delta'] = Downloads::peek() - $baseline;

         $Reflection = new ReflectionClass($Decoder);
         $Decoded = $Reflection->getProperty('decoded');
         $Decoded->setValue($Decoder, time() - 61);

         $late = "GET /must-not-be-redispatched HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $State = $Decoder->decode($Package, $late, strlen($late));

         $probe['timeout_state'] = $State->name;
         $probe['timeout_rejection'] = $Package->rejection;
         $probe['timeout_consumed'] = $Package->consumed;
         $probe['timeout_after_files'] = count(array_diff($Scan(), $beforeFiles));
         $probe['timeout_after_delta'] = Downloads::peek() - $baseline;

         // # Decoder destruction without a transport callback
         $boundary = 'Bootgly-H7-Destructor-Boundary';
         $partial = $Partial($boundary, 'H7-DESTRUCTOR-OWNED-');
         $Request = new Request;
         $Request->Body->waiting = true;
         $Request->Body->streaming = true;
         $Request->Body->length = strlen($partial) + 65536;
         $WPI->Request = $Request;

         $DroppedPackage = $Build();
         $DroppedDecoder = new Decoder_Downloading;
         $DroppedDecoder->Request = $Request;
         $DroppedDecoder->init($boundary);
         $DroppedPackage->Decoder = $DroppedDecoder;
         $DroppedDecoder->decode($DroppedPackage, $partial, strlen($partial));

         $probe['destructor_before_files'] = count(array_diff($Scan(), $beforeFiles));
         $probe['destructor_before_delta'] = Downloads::peek() - $baseline;

         $DroppedPackage->Decoder = null;
         unset($DroppedDecoder);
         gc_collect_cycles();

         $probe['destructor_after_files'] = count(array_diff($Scan(), $beforeFiles));
         $probe['destructor_after_delta'] = Downloads::peek() - $baseline;
         $probe['fallback_called'] = $Fallback->called;
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

         $WPI->Request = $OldRequest;
         Server::$Decoder = $OldDecoder;
      }

      return "GET /h7-timeout-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h7-timeout-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H7-TIMEOUT-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'H7-TIMEOUT-HARNESS-OK')) {
         return 'H7 timeout harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if (
         $probe['timeout_before_files'] < 1
         || $probe['timeout_before_delta'] <= 0
         || $probe['destructor_before_files'] < 1
         || $probe['destructor_before_delta'] <= 0
      ) {
         Vars::$labels = ['H7 timeout/destructor setup'];
         dump(json_encode($probe));
         return 'H7 cleanup probes did not materialize owned partial uploads.';
      }

      if (
         $probe['timeout_state'] !== States::Rejected->name
         || ! str_contains($probe['timeout_rejection'], '408 Request Timeout')
         || $probe['timeout_consumed'] !== 0
         || $probe['fallback_called']
      ) {
         Vars::$labels = ['H7 timeout outcome'];
         dump(json_encode($probe));
         return 'Expired multipart bytes were not rejected without redispatch.';
      }

      if (
         $probe['timeout_after_files'] !== 0
         || $probe['timeout_after_delta'] !== 0
         || $probe['destructor_after_files'] !== 0
         || $probe['destructor_after_delta'] !== 0
      ) {
         Vars::$labels = ['H7 timeout/destructor cleanup'];
         dump(json_encode($probe));
         return 'Timeout or decoder destruction left multipart files/reservations behind.';
      }

      return true;
   }
);

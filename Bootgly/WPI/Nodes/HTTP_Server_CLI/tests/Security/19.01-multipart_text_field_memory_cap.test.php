<?php

use function fclose;
use function is_resource;
use function json_encode;
use function strlen;
use function str_contains;
use function str_repeat;
use function time;
use function tmpfile;

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use ReflectionProperty;
use Throwable;


if (! class_exists('HTTPServerCLIMultipartFieldConnection', false)) {
   class HTTPServerCLIMultipartFieldConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         if (is_resource($this->Socket)) {
            @fclose($this->Socket);
         }

         return true;
      }
   }
}

/**
 * PoC — multipart text fields are buffered without a per-field cap.
 *
 * The request remains below Request::$maxFileSize, but one text field is
 * larger than the intended safe field cap. Vulnerable behavior accepts it,
 * appends it to Decoder_Downloading::$fieldBuffer, duplicates it into
 * $postEncoded, and exposes the whole value in $_POST.
 */

$probe = [
   'error' => '',
   'decoded' => null,
   'rejected' => null,
   'rejectRaw' => '',
   'postLength' => null,
   'closed' => null,
];

return new Specification(
   description: 'Multipart text fields must be capped independently of file size',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate temporary stream socket surrogate.';
         return "GET /multipart-field-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      }

      $WPI = WPI;

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldWPIRequest = $WPI->Request;
      $OldWPIResponse = $WPI->Response;
      $OldWPIRouter = $WPI->Router;

      try {
         $Connection = new HTTPServerCLIMultipartFieldConnection($socket);

         $Package = new class($Connection) extends TCPPackages {
            public bool $rejected = false;
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
               $this->Connection->close();
            }
         };

         Server::$Request = new Request;
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;

         $WPI->Request = Server::$Request;
         $WPI->Response = Server::$Response;
         $WPI->Router = Server::$Router;

         $boundary = '----BootglyMultipartFieldLimit';
         $field = str_repeat('A', 1048577); // 1 MiB + 1 byte
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"payload\"\r\n"
            . "\r\n"
            . $field . "\r\n"
            . "--{$boundary}--\r\n";
         $raw = "POST /multipart-field-direct HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

         $size = strlen($raw);
         $probe['decoded'] = Server::$Request->decode($Package, $raw, $size);
         $probe['rejected'] = $Package->rejected;
         $probe['rejectRaw'] = $Package->rejectRaw;
         $probe['closed'] = $Connection->closed;

         // @ Probe internal storage via Reflection: bypasses the $fields
         //   getter (which reads $Request->method that is uninitialized
         //   in this minimal harness) to assert the oversize text field
         //   was NOT materialized into Request state.
         try {
            $Reflection = new ReflectionProperty(Request::class, '_fields');
            /** @var array<string,mixed> $stored */
            $stored = $Reflection->getValue(Server::$Request) ?: [];
            $payload = $stored['payload'] ?? null;
         }
         catch (Throwable) {
            $payload = null;
         }
         $probe['postLength'] = is_string($payload) ? strlen($payload) : null;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
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

      return "GET /multipart-field-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/multipart-field-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if ($probe['rejected'] !== true || ! str_contains($probe['rejectRaw'], '413 Request Entity Too Large')) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Multipart text field larger than the safe per-field cap was accepted and buffered.';
      }

      if ($probe['postLength'] !== null) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Oversized multipart text field was still exposed in $Request->fields.';
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode($response));
         return 'Harness request did not reach /multipart-field-harness.';
      }

      return true;
   }
);

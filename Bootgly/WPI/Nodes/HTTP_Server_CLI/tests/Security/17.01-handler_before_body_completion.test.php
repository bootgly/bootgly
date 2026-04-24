<?php

use function fclose;
use function is_resource;
use function json_encode;
use function strlen;
use function str_contains;
use function tmpfile;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use ReflectionClass;
use Throwable;


/**
 * PoC — production Encoder_ executes the application before the request body
 * is complete.
 *
 * Test-mode Encoder_Testing already guards Body->waiting before it installs a
 * handler. The production Encoder_ path did the guard only in `finally`, after
 * routing/middleware/user handler execution. This deterministic unit probe runs
 * inside suite 20 and calls Encoder_ directly with an incomplete
 * Content-Length request to validate the production path.
 */

$probe = [
   'error' => '',
   'prematureHits' => null,
   'finalHits' => null,
   'prematureOutput' => '',
   'finalOutput' => '',
];

return new Specification(
   description: 'Production Encoder_ must not execute handlers before body completion',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate temporary stream socket surrogate.';
         return "GET /body-waiting-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      }

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;

      try {
         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Connection->Socket = $socket;
         $Connection->timers = [];
         $Connection->ip = '127.0.0.1';
         $Connection->port = 12345;
         $Connection->encrypted = false;

         $Package = new class($Connection) extends TCPPackages {
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
         };

         Server::$Request = new Request;
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;

         SAPI::$Middlewares = new Middlewares;
         $hits = 0;
         SAPI::$Handler = static function (Request $Request, Response $Response, Router $Router) use (&$hits): Response {
            $hits++;

            return $Response(code: 200, body: 'HITS:' . (string) $hits);
         };

         $raw = "POST /body-waiting-production HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n";
         $size = strlen($raw);
         $decoded = Server::$Request->decode($Package, $raw, $size);
         if ($decoded <= 0 || Server::$Request->Body->waiting === false) {
            $probe['error'] = 'Probe setup failed: request was not left waiting for body bytes.';
            return "GET /body-waiting-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         }

         $length = null;
         $probe['prematureOutput'] = Encoder_::encode($Package, $length);
         $probe['prematureHits'] = $hits;

         $body = 'abcde';
         $bodyLength = strlen($body);
         $completed = $Package->Decoder?->decode($Package, $body, $bodyLength) ?? 0;
         if ($completed <= 0 || Server::$Request->Body->waiting === true) {
            $probe['error'] = 'Probe setup failed: decoder did not complete after body bytes.';
            return "GET /body-waiting-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         }

         $length = null;
         $probe['finalOutput'] = Encoder_::encode($Package, $length);
         $probe['finalHits'] = $hits;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }

         @fclose($socket);
      }

      return "GET /body-waiting-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/body-waiting-harness', function (Request $Request, Response $Response) {
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

      if ($probe['prematureHits'] !== 0) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Production Encoder_ executed the handler while Body->waiting=true.';
      }

      if ($probe['finalHits'] !== 1) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Handler should execute exactly once after the body completes.';
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode($response));
         return 'Harness request did not reach /body-waiting-harness.';
      }

      return true;
   }
);
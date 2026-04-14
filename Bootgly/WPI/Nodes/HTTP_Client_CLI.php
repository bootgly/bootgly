<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const BOOTGLY_ROOT_DIR;
use const SIGTERM;
use const STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
use const STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function str_replace;
use function stream_context_create;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function stripos;
use function strlen;
use function strpos;
use function substr;
use function usleep;
use Closure;
use Generator;
use Throwable;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Workables\Client as CAPI;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoder;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification as E2ESpecification;


class HTTP_Client_CLI extends TCP_Client_CLI implements HTTP
{
   // * Config
   public static null|string $targetHost = null;
   public static null|int $targetPort = null;

   // * Data
   // # Protocol
   /** @var Encoder */
   public static $Encoder;
   // # Hooks
   protected static null|Closure $onResponse = null;
   protected static null|Closure $httpOnConnect = null;
   protected static null|Closure $httpOnWrite = null;

   // * Metadata
   protected static bool $eventDriven = false;
   public static int $bytesReceived = 0;

   // # Request pipeline
   /** @var array<int,Request> Pending requests keyed by socket ID */
   protected array $pendingRequests = [];
   /** Whether wire() callbacks are configured on the event loop */
   protected bool $wired = false;
   /** Whether requests are being batched (deferred drain) */
   protected bool $batching = false;
   /** Next Request for the connect callback (set before connect()) */
   protected null|Request $nextRequest = null;


   public function __construct (int $mode = self::MODE_DEFAULT)
   {
      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      self::$eventDriven = false;
      self::$bytesReceived = 0;


      // \
      parent::__construct($mode);

      // @ Configure Logger
      $this->Logger = new Logger(channel: 'HTTP.Client.CLI');

      // . Request/Encoder
      self::$Encoder = new Encoder_;
   }

   /**
    * Configure the HTTP Client.
    *
    * @param string $host Target host to connect to.
    * @param int $port Target port to connect to.
    * @param int $workers Number of worker processes.
    * @param array<string,mixed>|null $ssl SSL Stream Context options.
    *
    * @return self
    */
   public function configure (string $host, int $port, int $workers = 0, null|array $ssl = null): self
   {
      // @ Auto-set peer_name for hostname verification if SSL is enabled
      if ($ssl !== null && !isset($ssl['peer_name'])) {
         $ssl['peer_name'] = $host;
      }

      parent::configure($host, $port, $workers, $ssl);

      // @ Store for Encoder/Decoder access
      self::$targetHost = $host;
      self::$targetPort = $port;

      return $this;
   }

   /**
    * Register hooks for the HTTP Client (event-driven mode).
    *
    * @param null|Closure $instance On worker instance callback.
    * @param null|Closure $connect On connection established callback.
    * @param null|Closure $disconnect On connection closed callback.
    * @param null|Closure(Request, Response): mixed $response On HTTP response received callback.
    *
    * @return void
    */
   public function on (
      null|Closure $instance = null,
      null|Closure $connect = null,
      null|Closure $disconnect = null,
      #[\SensitiveParameter] null|Closure $read = null,
      #[\SensitiveParameter] null|Closure $write = null,
      null|Closure $response = null
   ): void
   {
      // @ Mark as event-driven mode
      self::$eventDriven = true;

      // @ Store hooks
      // # TCP Client context
      self::$onInstance = $instance;
      self::$onDisconnect = $disconnect;
      // # HTTP Client context
      self::$onResponse = $response;
      self::$httpOnConnect = $connect;
      self::$httpOnWrite = $write;
   }

   /**
    * Wire unified connect/read/write callbacks on the event loop.
    * Idempotent — only wires once until reset by drain().
    *
    * @return void
    */
   private function wire (): void
   {
      if ($this->wired) {
         return;
      }
      $this->wired = true;

      $HTTP_Client_CLI = $this;

      // @ On connect: encode and queue the request for writing
      parent::$onConnect = function ($Socket, $Connection) use ($HTTP_Client_CLI) {
         // @ Call user's connect hook if set
         if (self::$httpOnConnect !== null) {
            (self::$httpOnConnect)($Socket, $Connection);
         }

         $template = $HTTP_Client_CLI->nextRequest;
         if ($template === null) {
            return;
         }

         // @ Event-driven: nextRequest is a shared template — keep it for other
         //   connections. Create a per-socket Request for decode state tracking.
         // @ Sync/batch: one-to-one mapping — consume nextRequest.
         if (self::$eventDriven) {
            $Request = new Request;
            $Request($template->method, $template->URI);
            $Request->connectionState = 'waiting';
         }
         else {
            $Request = $template;
            $HTTP_Client_CLI->nextRequest = null;
         }

         $socketId = (int) $Socket;
         $HTTP_Client_CLI->pendingRequests[$socketId] = $Request;

         $headerRaw = $template->Header->build();
         $length = null;

         // @ Detect Expect: 100-continue — send headers only, defer body
         if (stripos($headerRaw, 'Expect: 100-continue') !== false
            && $template->Body->raw !== ''
         ) {
            $Connection->output = self::$Encoder::encode(
               $template->method,
               $template->URI,
               $template->protocol,
               $headerRaw,
               host: self::$targetHost ?? '127.0.0.1',
               port: self::$targetPort ?? 80,
               length: $length
            );
            $Request->connectionState = 'waiting-100-continue';
         }
         else {
            $Connection->output = self::$Encoder::encode(
               $template->method,
               $template->URI,
               $template->protocol,
               $headerRaw,
               $template->Body->raw,
               self::$targetHost ?? '127.0.0.1',
               self::$targetPort ?? 80,
               $length
            );
         }

         self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);
      };

      // @ On read: decode response using per-request state
      self::$onRead = function ($Socket, $Connection) use ($HTTP_Client_CLI) {
         $socketId = (int) $Socket;
         $Request = $HTTP_Client_CLI->pendingRequests[$socketId] ?? null;
         if ($Request === null) {
            return;
         }

         // @ Accumulate new bytes into per-request pending buffer
         $newBytes = $Connection->input;
         $newSize = strlen($newBytes);

         if ($newSize > 0) {
            self::$bytesReceived += $newSize;
            $Request->bytesReceived += $newSize;
            $Request->pendingBuffer .= $newBytes;
         }
         else if ($Request->pendingBuffer === '') {
            return; // @ No new data and no pending buffer, nothing to process
         }

         $buffer = $Request->pendingBuffer;
         $size = strlen($buffer);

         parse_response:
         $parsed = $Request->Decoder->decode($buffer, $size, $Request->method);

         if ($parsed === null) {
            return; // @ Incomplete headers or chunk data, wait for more
         }

         // @ Handle 1xx informational: slice consumed bytes, wait for final response
         if ($parsed['interim'] ?? false) {
            $Request->pendingBuffer = substr($buffer, (int) $parsed['consumed']);

            // @ 100 Continue: server accepted Expect, now send deferred body
            if ($parsed['code'] === 100
               && $Request->connectionState === 'waiting-100-continue'
            ) {
               $Connection->output = $Request->Body->raw;
               $Request->connectionState = 'waiting';
               self::$Event->del($Socket, self::$Event::EVENT_READ);
               self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);
               return;
            }

            // @ Non-100 interim (e.g. 102 Processing): process pending buffer immediately
            if ($Request->pendingBuffer !== '') {
               $buffer = $Request->pendingBuffer;
               $Request->pendingBuffer = '';
               goto parse_response;
            }

            return;
         }

         // @ Handle chunked transfer complete
         if (isSet($parsed['complete'])) {
            $Request->Response->Body->raw = (string) $parsed['body'];
            $Request->Response->Body->length = (int) $parsed['bodyLength'];
            $Request->Response->Body->downloaded = (int) $parsed['bodyLength'];
            $Request->Response->Body->waiting = false;

            // @ Restore any pipelined bytes after the chunked body
            $Request->pendingBuffer = (string) $parsed['leftover'];

            // @ Restore default decoder
            $Request->Decoder = new Decoder_;
         }
         else {
            $consumed = (int) $parsed['consumed'];

            // @ Populate Response DTO from parsed data
            $Response = $Request->Response;
            $Response->protocol = (string) $parsed['protocol'];
            $Response->code = (int) $parsed['code'];
            $Response->status = (string) $parsed['status'];
            $Response->closeConnection = (bool) $parsed['closeConnection'];

            $Response->Header->define((string) $parsed['headerRaw']);            $Response->Header->build();
            $Response->Body->raw = (string) $parsed['bodyRaw'];
            $Response->Body->length = (int) $parsed['bodyLength'];
            $Response->Body->downloaded = (int) $parsed['bodyDownloaded'];
            $Response->Body->waiting = (bool) $parsed['bodyWaiting'];

            // @ Handle chunked transfer-encoding switch
            if ($parsed['chunked']) {
               // @ Slice headers from pending buffer; remaining bytes = initial chunk data
               $chunkInit = substr($buffer, $consumed);
               // @ Hand off to chunked decoder; clear pending buffer (decoder owns the bytes now)
               $Request->pendingBuffer = '';
               $Chunked = new Decoder_Chunked;
               $Chunked->init();
               if ($chunkInit !== '') {
                  $Chunked->feed($chunkInit);
               }
               $Request->Decoder = $Chunked;

               // @ Try immediate decode: all chunk data may already be buffered
               $chunkedResult = $Chunked->decode('', 0, $Request->method);
               if ($chunkedResult !== null) {
                  $Request->Response->Body->raw = (string) $chunkedResult['body'];
                  $Request->Response->Body->length = (int) $chunkedResult['bodyLength'];
                  $Request->Response->Body->downloaded = (int) $chunkedResult['bodyLength'];
                  $Request->Response->Body->waiting = false;
                  $Request->pendingBuffer = (string) $chunkedResult['leftover'];
                  $Request->Decoder = new Decoder_;
                  // @ Fall through to fire response callback
               }
               else {
                  // @ Still incomplete, wait for more data
                  return;
               }
            }
            else {
               // @ Slice consumed bytes; preserve any pipelined/leftover bytes
               $Request->pendingBuffer = substr($buffer, $consumed);

               // @ Body not yet complete: wait for more data before firing callback
               if ($parsed['bodyWaiting']) {
                  return;
               }
            }
         }

         // @ Response complete — branch by mode
         if (self::$eventDriven) {
            // @ Event-driven: fire hook, reuse connection
            $Request->connectionState = 'idle';

            if (self::$onResponse !== null) {
               (self::$onResponse)($Request, $Request->Response);
            }

            // @ Handle connection close
            if ($Request->Response->closeConnection) {
               unset($HTTP_Client_CLI->pendingRequests[$socketId]);
               $Connection->close();
               return;
            }

            // @ Auto-send next request if callback queued one via request()
            if ($HTTP_Client_CLI->nextRequest !== null) {
               $next = $HTTP_Client_CLI->nextRequest;
               $HTTP_Client_CLI->nextRequest = null;
               $HTTP_Client_CLI->pendingRequests[$socketId] = $next;

               $headerRaw = $next->Header->build();
               $length = null;

               // @ Detect Expect: 100-continue — send headers only, defer body
               if (stripos($headerRaw, 'Expect: 100-continue') !== false
                  && $next->Body->raw !== ''
               ) {
                  $Connection->output = self::$Encoder::encode(
                     $next->method,
                     $next->URI,
                     $next->protocol,
                     $headerRaw,
                     host: self::$targetHost ?? '127.0.0.1',
                     port: self::$targetPort ?? 80,
                     length: $length
                  );
                  $next->connectionState = 'waiting-100-continue';
               }
               else {
                  $Connection->output = self::$Encoder::encode(
                     $next->method,
                     $next->URI,
                     $next->protocol,
                     $headerRaw,
                     $next->Body->raw,
                     self::$targetHost ?? '127.0.0.1',
                     self::$targetPort ?? 80,
                     $length
                  );
               }

               self::$Event->del($Socket, self::$Event::EVENT_READ);
               self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);
            }
         }
         else {
            // @ Sync/batch: mark complete, close connection
            $Request->completed = true;
            $Request->connectionState = 'idle';
            unset($HTTP_Client_CLI->pendingRequests[$socketId]);

            if ($Request->onComplete !== null) {
               ($Request->onComplete)($Request);
            }

            $Connection->close();

            // @ If all pending requests are done, stop the event loop
            if (empty($HTTP_Client_CLI->pendingRequests)) {
               self::$Event->destroy();
            }
         }
      };

      // @ After write completes, switch to read mode
      self::$onWrite = function ($Socket, $Connection) {
         self::$Event->del($Socket, self::$Event::EVENT_WRITE);
         self::$Event->add($Socket, self::$Event::EVENT_READ, $Connection);

         if (self::$httpOnWrite !== null) {
            (self::$httpOnWrite)($Socket, $Connection);
         }
      };
   }

   /**
    * Run the event loop until all pending requests complete.
    *
    * @return void
    */
   public function drain (): void
   {
      if (empty($this->pendingRequests)) {
         return;
      }

      self::$Event->loop();

      // @ Reset for next batch of requests
      $this->wired = false;
      $this->batching = false;
   }
   /**
    * Enter batch mode: subsequent request() calls are deferred
    * until drain() is called. Enables concurrent request execution.
    *
    * @return void
    */
   public function batch (): void
   {
      $this->batching = true;
   }

   /**
    * Send an HTTP request.
    *
    * In event-driven mode (after `on()` was called): prepares the request
    * and marks it as pending for auto-send by the event loop.
    *
    * In synchronous mode (default): connects, sends the request,
    * and waits for the response.
    *
    * @param string $method HTTP method.
    * @param string $URI Request URI.
    * @param array<string,string> $headers Additional headers.
    * @param mixed $body Request body.
    *
    * @return self|Response Self in event-driven mode, Response in sync mode.
    */
   public function request (
      string $method = 'GET',
      string $URI = '/',
      array $headers = [],
      mixed $body = null
   ): self|Response
   {
      // @ Create and prepare request with transport state
      $Request = new Request;
      $Request($method, $URI, $headers, $body);
      $Request->connectionState = 'waiting';

      $this->nextRequest = $Request;

      // @ Event-driven mode: store request, return self
      if (self::$eventDriven) {
         $this->wire();
         return $this;
      }

      // @ Sync/batch mode
      // @ Ensure event loop infrastructure exists
      if (!$this->wired) {
         $Connections = new Connections($this);
         
         $this->Connections = $Connections;

         self::$Event = new Select($this->Connections);
      }
      $this->wire();

      // @ Connect (fires connect callback which sends the request)
      $Socket = $this->connect();
      if ($Socket === false) {
         $this->nextRequest = null;
         $Request->completed = true;
         return $Request->Response;
      }

      // @ Batch mode: return Response reference (filled later by drain())
      if ($this->batching) {
         return $Request->Response;
      }

      // @ Sync mode: drain and return completed Response
      $this->drain();

      return $Request->Response;
   }

   // # Testing
   /**
    * Pre-test setup: load E2E test specifications.
    *
    * @param null|Suite $Suite The test suite.
    *
    * @return void
    */
   public static function pretest (null|Suite $Suite, string $testsDir = 'E2E'): void
   {
      if ($Suite === null) {
         return;
      }

      $originalTests = $Suite->tests;
      $target = $Suite->target ?? 0;

      $selected = [];
      if ($target > 0) {
         $index = $target - 1;
         if (isset($originalTests[$index])) {
            $selected[$index] = $originalTests[$index];
         }
      }
      else {
         foreach ($originalTests as $index => $case) {
            $selected[$index] = $case;
         }
      }

      CAPI::$Suite = $Suite;
      CAPI::$Tests[self::class] = [];
      CAPI::$tests[self::class] = [];

      // @ Convert namespace to path (backslash -> forward slash)
      $classPath = str_replace('\\', '/', __CLASS__);

      foreach ($selected as $index => $case) {
         $Test_Case_File = new File(
            BOOTGLY_ROOT_DIR . $classPath . '/tests/' . $testsDir . '/' . $case . '.test.php'
         );
         if ($Test_Case_File->exists === false) {
            continue;
         }

         try {
            /** @var Specification|null $test */
            $test = require $Test_Case_File;
         }
         catch (Throwable) {
            $test = null;
         }

         if ($test instanceof Specification) {
            $test->index(case: $index + 1);
         }
         CAPI::$Tests[self::class][] = $test;
         CAPI::$tests[self::class][] = $case;

         // @ Expand handler queue for multi-request tests
         if (
            $test instanceof E2ESpecification
            && $test->requests !== []
         ) {
            $extra = count($test->requests) - 1;
            for ($i = 0; $i < $extra; $i++) {
               CAPI::$Tests[self::class][] = $test;
            }
         }
      }

      $Suite->tests = CAPI::$tests[self::class];
   }
   /**
    * Run E2E tests using a mock TCP server.
    *
    * @param int $port Mock server port.
    * @param array<string,mixed>|null $ssl SSL context for mock server (local_cert, local_pk, etc.)
    *
    * @return bool True if tests completed.
    */
   public static function test (int $port = 9999, null|array $ssl = null): bool
   {
      Logger::$display = Logger::DISPLAY_NONE;

      // @ Start mock TCP server in background (fork)
      $process_id = pcntl_fork();
      if ($process_id === -1) {
         return false;
      }

      // # Server
      if ($process_id === 0) {
         // @ Child process: run mock TCP server
         // @ Create SSL context for server if needed
         if ($ssl !== null) {
            $serverContext = stream_context_create(['ssl' => $ssl]);
         }
         else {
            $serverContext = stream_context_create();
         }

         // @ Create simple TCP server socket (blocking, synchronous)
         $server = stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $serverContext
         );

         if ($server === false) {
            exit(1);
         }

         $specIndex = 0;
         $requestIndex = 0;

         // @@ Accept one connection at a time (blocking)
         while ($client = @stream_socket_accept($server, 10)) {
            // @@ Enable TLS on accepted client if SSL configured
            if ($ssl !== null) {
               $crypto = @stream_socket_enable_crypto(
                  $client,
                  true,
                  STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
               );
               if ($crypto !== true) {
                  @fclose($client);
                  continue;
               }
            }

            // @@ Read request headers (blocking)
            $input = '';
            while (true) {
               $chunk = @fread($client, 65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $input .= $chunk;
               // @ Check if we have complete HTTP request headers (\r\n\r\n)
               if (strpos($input, "\r\n\r\n") !== false) {
                  break;
               }
            }

            // @ Handle Expect: 100-continue — two-phase response
            $has100Continue = stripos($input, 'Expect: 100-continue') !== false;
            if ($has100Continue) {
               // @ Send interim 100 Continue
               @fwrite($client, "HTTP/1.1 100 Continue\r\n\r\n");

               // @ Read body (sent after client receives 100)
               $body = '';
               $bodyChunk = @fread($client, 65536);
               if ($bodyChunk !== false && $bodyChunk !== '') {
                  $body = $bodyChunk;
               }
               $input .= $body;
            }

            // @ Get response for this request
            /** @var E2ESpecification|null $spec */
            $spec = CAPI::$Tests[self::class][$specIndex] ?? null;

            if ($spec === null) {
               $response = "HTTP/1.1 500 Internal Server Error\r\n\r\n";
            } else if ($spec->responses !== []) {
               // Multi-request test
               $responseFactory = $spec->responses[$requestIndex] ?? null;
               $requestIndex++;
               if ($requestIndex >= count($spec->responses)) {
                  $requestIndex = 0;
                  $specIndex++;
               }

               if ($responseFactory instanceof Closure === false) {
                  $response = "HTTP/1.1 500 Internal Server Error\r\n\r\n";
               } else {
                  $response = $responseFactory($input);
               }
            } else {
               // Single-request test
               $responseFactory = $spec->response;
               $specIndex++;
               $response = $responseFactory($input);
            }

            // @ Send response (string or Generator for chunked/multi-phase sends)
            if ($response instanceof Generator) {
               foreach ($response as $chunk) {
                  @fwrite($client, (string) $chunk); // @phpstan-ignore cast.string
                  usleep(10000); // 10ms between chunks
               }
            } else {
               @fwrite($client, $response);
            }

            // @ Allow client to read before we close
            usleep(10000); // 10ms
            @fclose($client);
         }

         @fclose($server);
         exit(0);
      }

      // # Client (parent process)
      // @ Parent process: wait for server to start
      usleep(100000); // 100ms

      // @ Run tests as client
      self::testing($port, $ssl !== null ? [
         'verify_peer'       => false,
         'verify_peer_name'  => false,
         'allow_self_signed' => true,
      ] : null);

      // @ Cleanup
      posix_kill($process_id, SIGTERM);
      pcntl_waitpid($process_id, $status);

      return true;
   }
   /**
    * Run client tests against the mock server (sequential sync mode).
    *
    * Each request completes before the next starts, keeping
    * client and mock server in lock-step order.
    *
    * @param int $port Mock server port.
    * @param array<string,mixed>|null $ssl SSL context options for the client.
    *
    * @return void
    */
   protected static function testing (int $port, null|array $ssl = null): void
   {
      Logger::$display = Logger::DISPLAY_MESSAGE;

      $Suite = CAPI::$Suite;
      $Suite->separate($Suite->name);

      $testFiles = CAPI::$tests[self::class] ?? [];
      $specIndex = 0;

      // @ Create a single reusable HTTP client instance (lightweight test mode)
      $HTTP_Client_CLI = new self(self::MODE_TEST);
      $HTTP_Client_CLI->configure('127.0.0.1', $port, ssl: $ssl);

      // # Run each test synchronously (sequential mode)
      // Each request completes before the next starts, keeping
      // client and mock server in lock-step order.
      foreach ($testFiles as $index => $value) {
         /** @var E2ESpecification|null $spec */
         $spec = CAPI::$Tests[self::class][$specIndex] ?? null;

         if (!($spec instanceof E2ESpecification)) {
            $specIndex++;
            continue;
         }

         // @ Collect responses
         $responses = [];

         if ($spec->requests !== []) {
            // @ Multi-request test: send each sub-request synchronously
            foreach ($spec->requests as $requestClosure) {
               $responses[] = $requestClosure($HTTP_Client_CLI);
            }
            $specIndex += count($spec->requests);
         }
         else {
            // @ Single-request test
            $requestClosure = $spec->request;
            $responses[] = $requestClosure($HTTP_Client_CLI);
            $specIndex++;
         }

         // @ Assert results
         if ($spec instanceof Specification) { // @phpstan-ignore instanceof.alwaysTrue
            $spec->index(case: $spec->case ?? ((int) $index + 1));
         }

         $Suite->case = $spec->case ?? ((int) $index + 1);

         $Test = $Suite->test($spec);
         if ($Test === null) {
            $Suite->skip();
            continue;
         }

         if (count($responses) > 1) {
            $Test->test($responses);
         }
         else {
            $Test->test($responses[0]);
         }

         if ($Test->passed) {
            $Test->pass();
         }
         else {
            $Test->fail();
            break;
         }
      }

      $Suite->summarize();

      Logger::$display = Logger::DISPLAY_MESSAGE;
   }
}

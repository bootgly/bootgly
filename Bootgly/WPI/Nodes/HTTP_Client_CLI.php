<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
use function array_shift;
use function array_unshift;
use function count;
use function ctype_digit;
use function fclose;
use function fread;
use function fwrite;
use function hrtime;
use function in_array;
use function max;
use function microtime;
use function min;
use function mt_rand;
use function parse_url;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function str_replace;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function stripos;
use function strlen;
use function strpos;
use function strrpos;
use function strtotime;
use function strtoupper;
use function substr;
use function time;
use function usleep;
use BackedEnum;
use Closure;
use Generator;
use InvalidArgumentException;
use Throwable;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Workables\Client as CAPI;
use Bootgly\WPI\Event;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Pool;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoder;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification as E2ESpecification;


class HTTP_Client_CLI extends TCP_Client_CLI implements HTTP
{
   // * Config
   public static null|string $targetHost = null;
   public static null|int $targetPort = null;
   // | Redirect
   /** Maximum number of redirects to follow (0 = disabled). */
   public int $maxRedirects = 10;
   // | Timeout
   /** Connection timeout in seconds (0 = no timeout). */
   public int|float $connectTimeout = 30;
   /** Response timeout in seconds (0 = no timeout). */
   public int|float $timeout = 30;
   /** Maximum raw response bytes (headers + body); 0 keeps compatibility/unbounded. */
   public int $maxResponseBytes = 0;
   // | Retry
   /** Maximum number of retries on connection/timeout failure (0 = disabled). */
   public int $maxRetries = 0;
   /** Base backoff delay in seconds — doubles on each retry attempt. */
   public int|float $retryDelay = 1.0;
   /** Backoff delay cap in seconds. */
   public int|float $retryMaxDelay = 30.0;
   /** Wall-clock retry campaign budget per request in seconds (0 = unbounded). */
   public int|float $retryTimeout = 60.0;
   /** Proportional jitter fraction applied to each backoff delay. */
   public float $retryJitter = 0.25;
   /**
    * Opt-in HTTP-level retry status codes (e.g. [429, 503]), honoring Retry-After.
    *
    * @var array<int,int>
    */
   public array $retryOn = [];
   /** Retry-After header clamp in seconds. */
   public const int MAX_RETRY_AFTER = 300;
   // | HTTP/2
   /**
    * null (default): offer h2 via TLS-ALPN when `secure` is set; cleartext stays HTTP/1.1.
    * true: also speak h2c prior-knowledge on cleartext connections (explicit opt-in).
    * false: never negotiate HTTP/2.
    */
   public null|bool $enableHTTP2 = null;

   // * Data
   // # Protocol
   public static Encoder $Encoder;
   // # Hooks
   protected static null|Closure $onResponse = null;
   protected static null|Closure $httpOnConnect = null;
   protected static null|Closure $httpOnRead = null;
   protected static null|Closure $httpOnWrite = null;

   // * Metadata
   protected static bool $eventDriven = false;
   public static int $bytesReceived = 0;
   // # Pool
   /** Per-origin connection pool (sync/batch modes). */
   public protected(set) Pool $Pool;
   /** @var array<int,Request> Requests queued beyond the pool capacity (batch overflow) */
   protected array $Queue = [];
   /** Whether the pool minimum was already warmed up. */
   private bool $warmed = false;
   // # Retry
   /** Retry re-dispatches scheduled on the event loop. */
   protected int $retrying = 0;
   // # HTTP/2 multiplexing
   /** @var array<int,Session> h2 Sessions keyed by socket ID */
   public protected(set) array $Sessions = [];
   /** @var array<int,array<int,Request>> Pending h2 requests keyed by socket ID → stream ID */
   protected array $pendingStreams = [];
   // # Request pipeline
   /** @var array<int,Request> Pending requests keyed by socket ID */
   protected array $pendingRequests = [];
   /** Whether wire() callbacks are configured on the event loop */
   protected bool $wired = false;
   /** Whether requests are being batched (deferred drain) */
   protected bool $batching = false;
   /** Next Request for the connect callback (set before connect()) */
   protected null|Request $nextRequest = null;
   // # Encoder cache (event-driven mode)
   /** @var array<string,string> method+URI => encoded output */
   protected static array $encoderCache = [];
   /** Cached Request template for event-driven reuse (avoids allocation per cycle) */
   protected null|Request $cachedRequest = null;


   public function __construct (int $mode = self::MODE_DEFAULT)
   {
      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      self::$eventDriven = false;
      self::$bytesReceived = 0;
      // # Pool
      $this->Pool = new Pool;


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
   * @param array<string,mixed>|null $secure Secure SSL/TLS Stream Context options.
    * @param array<string,int>|null $pool Connection pool bounds: ['min' => N, 'max' => N].
    * @param null|bool $enableHTTP2 HTTP/2 negotiation (null = ALPN when secure; true = also h2c; false = never).
    *
    * @return self
    */
   public function configure (
      string $host,
      int $port,
      int $workers = 0,
      null|array $secure = null,
      null|array $pool = null,
      null|bool $enableHTTP2 = null
   ): self
   {
      // @ Auto-set peer_name for hostname verification if secure transport is enabled
      if ($secure !== null && !isset($secure['peer_name'])) {
         $secure['peer_name'] = $host;
      }

      // # HTTP/2
      if ($enableHTTP2 !== null) {
         $this->enableHTTP2 = $enableHTTP2;
      }
      // @ Offer h2 via TLS-ALPN unless HTTP/2 is disabled
      if ($secure !== null && $this->enableHTTP2 !== false) {
         $secure['alpn_protocols'] ??= 'h2,http/1.1';
      }

      parent::configure($host, $port, $workers, $secure);

      // @ Store for Encoder/Decoder access
      self::$targetHost = $host;
      self::$targetPort = $port;

      // ---
      // ! The pool is per-origin by construction: reconfiguring retires every
      //   pooled connection from the previous origin.
      foreach ($this->Pool->busy + $this->Pool->idle as $Connection) {
         $Connection->close();
      }

      // ? Keep the previous pool bounds when none are given (redirect reconfigure)
      $pool ??= ['min' => $this->Pool->min, 'max' => $this->Pool->max];

      $this->Pool = new Pool($pool);
      $this->warmed = false;

      return $this;
   }

   /**
    * Register an event handler for the HTTP Client.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof Events === false) {
         throw new InvalidArgumentException('Invalid HTTP Client event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }
      $this->Events[$Event->value] = true;

      // @ Mark as event-driven mode
      self::$eventDriven = true;

      match ($Event) {
         Events::WorkerStarted => self::$onWorkerStarted = $Callback,
         Events::ClientConnect => self::$httpOnConnect = $Callback,
         Events::ClientDisconnect => self::$onClientDisconnect = $Callback,
         Events::DataRead => self::$httpOnRead = $Callback,
         Events::DataWrite => self::$httpOnWrite = $Callback,
         Events::ResponseReceive => self::$onResponse = $Callback,
      };

      return $this;
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

      // @ On client connection: encode and queue the request for writing
      parent::$onClientConnect = function ($Socket, $Connection) use ($HTTP_Client_CLI) {
         // @ Call user's connect hook if set
         if (self::$httpOnConnect !== null) {
            (self::$httpOnConnect)($Socket, $Connection);
         }

         $Template = $HTTP_Client_CLI->nextRequest;

         // # HTTP/2 negotiation (sync/batch modes)
         if (self::$eventDriven === false) {
            $h2 = false;
            if ($Connection->encrypted) {
               // @ TLS: honor the ALPN-negotiated protocol
               $meta = stream_get_meta_data($Socket);
               $h2 = ($meta['crypto']['alpn_protocol'] ?? '') === 'h2';
            }
            else if ($HTTP_Client_CLI->enableHTTP2 === true) {
               // @ Cleartext: h2c prior knowledge (explicit opt-in)
               $h2 = true;
            }

            if ($h2) {
               $socketId = (int) $Socket;

               // ! One Session per h2 connection — the constructor queues the
               //   client preface + SETTINGS into its outbox
               $Session = new Session;
               $Session->limit = $HTTP_Client_CLI->maxResponseBytes;
               $HTTP_Client_CLI->Sessions[$socketId] = $Session;

               $HTTP_Client_CLI->Pool->attach(
                  $Connection,
                  capacity: $Session->capacity,
                  busy: $Template !== null
               );

               if ($Template !== null) {
                  $HTTP_Client_CLI->nextRequest = null;

                  // ? A fresh Session refusing the stream is a permanent
                  //   per-request failure (header list beyond the peer cap)
                  if ($HTTP_Client_CLI->submit($Session, $socketId, $Template) === 0) {
                     $Template->Response->code = 0;
                     $Template->Response->status = 'Request Header Fields Too Large';
                     $Template->completed = true;
                     $Template->connectionState = 'idle';

                     if ($Template->onComplete !== null) {
                        ($Template->onComplete)($Template);
                     }

                     $HTTP_Client_CLI->Pool->release($Connection);
                  }
               }

               // @ Ship preface + SETTINGS (+ HEADERS/DATA) in one write
               $HTTP_Client_CLI->flush($Session, $Connection);

               return;
            }
         }

         // @ Register the fresh h1 connection in the per-origin pool (sync/batch)
         if (self::$eventDriven === false) {
            $HTTP_Client_CLI->Pool->attach($Connection, capacity: 1, busy: $Template !== null);
         }

         // ? Warm-up dial — the connection parks idle in the pool
         if ($Template === null) {
            return;
         }

         // @ Event-driven: nextRequest is a shared template — keep it for other
         //   connections. Create a per-socket Request for decode state tracking.
         // @ Sync/batch: one-to-one mapping — consume nextRequest.
         if (self::$eventDriven) {
            $Request = new Request;
            $Request($Template->method, $Template->URI);
            $Request->connectionState = 'waiting';

            $HTTP_Client_CLI->send($Request, $Connection, $Template);
         }
         else {
            $HTTP_Client_CLI->nextRequest = null;

            $HTTP_Client_CLI->send($Template, $Connection);
         }
      };

      // @ On read: decode response using per-request state
      self::$onDataRead = function ($Socket, $Connection) use ($HTTP_Client_CLI) {
         // # One monotonic arrival timestamp follows this chunk through decoding
         //   to the logical response callback. Parser work must not inflate wire
         //   latency or move an in-window final byte beyond the cutoff.
         $receivedNS = (int) hrtime(true);
         if (self::$httpOnRead !== null) {
            (self::$httpOnRead)($Socket, $Connection, $receivedNS);
         }

         $socketId = (int) $Socket;

         // # HTTP/2 (multiplexed Session)
         $Session = $HTTP_Client_CLI->Sessions[$socketId] ?? null;
         if ($Session !== null) {
            $HTTP_Client_CLI->receive($Session, $Connection, $socketId);
            return;
         }

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

            if (
               $HTTP_Client_CLI->maxResponseBytes > 0
               && $Request->bytesReceived > $HTTP_Client_CLI->maxResponseBytes
            ) {
               $Request->Response->code = 0;
               $Request->Response->status = 'Response Too Large';
               $Request->completed = true;
               $Request->connectionState = 'idle';
               unset($HTTP_Client_CLI->pendingRequests[$socketId]);

               if ($Request->onComplete !== null) {
                  ($Request->onComplete)($Request);
               }

               // @ close() fires the disconnect hook: pool drop + promote + halt
               $Connection->close();
               return;
            }

            // @ Avoid string concat when buffer is empty (direct assignment)
            if ($Request->pendingBuffer === '') {
               $Request->pendingBuffer = $newBytes;
            }
            else {
               $Request->pendingBuffer .= $newBytes;
            }
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
               $Request->pendingBuffer = $consumed >= $size ? '' : substr($buffer, $consumed);

               // @ Body not yet complete: wait for more data before firing callback
               if ($parsed['bodyWaiting']) {
                  return;
               }
            }
         }

         // @ Response complete — handle redirect if applicable
         $Response = $Request->Response;
         $redirectCode = $Response->code;
         if (
            $HTTP_Client_CLI->maxRedirects > 0
            && $Request->redirectCount < $HTTP_Client_CLI->maxRedirects
            && ($redirectCode === 301 || $redirectCode === 302 || $redirectCode === 303
               || $redirectCode === 307 || $redirectCode === 308)
         ) {
            $location = $Response->Header->get('Location');
            if ($location !== null && $location !== '') {
               $Request->redirectCount++;

               // @ Save original method/body on first redirect
               if ($Request->originalMethod === '') {
                  $Request->originalMethod = $Request->method;
                  $Request->originalBody = $Request->Body->raw;
               }

               // @ Determine new method per RFC 7231
               // 301/302/303: change to GET (except HEAD stays HEAD), clear body
               // 307/308: preserve original method and body
               if ($redirectCode === 301 || $redirectCode === 302 || $redirectCode === 303) {
                  if ($Request->method !== 'HEAD') {
                     $Request->method = 'GET';
                  }
                  $Request->clear();
               }

               // @ Resolve redirect URL
               $resolved = $HTTP_Client_CLI->resolve($location, $Request->URI);

               $Request->URI = $resolved['path'];
               $Request->pendingBuffer = '';
               $Request->Decoder = new Decoder_;
               $Request->connectionState = 'redirect';

               // @ Store resolved target for reconnection in request()
               $Request->redirectTarget = $resolved;

               // @ Check if redirect target is same host/port/scheme
               $sameHost = ($resolved['host'] === (self::$targetHost ?? '127.0.0.1'))
                  && ($resolved['port'] === (self::$targetPort ?? 80))
                  && ($resolved['secure'] === ($HTTP_Client_CLI->secure !== null));

               if ($sameHost && !$Response->closeConnection) {
                  // @ Same host + keep-alive: reuse connection
                  $Request->Response->reset();
                  $Request->connectionState = 'waiting';
                  // ! Each redirect leg gets its own maxResponseBytes budget
                  $Request->bytesReceived = 0;

                  $HTTP_Client_CLI->send($Request, $Connection);
               }
               else {
                  // @ Close current connection; cross-origin reconnection is
                  //   handled by the sync follow() loop. halt() (not a bare
                  //   destroy) keeps batch siblings and scheduled retries alive.
                  unset($HTTP_Client_CLI->pendingRequests[$socketId]);
                  $Connection->close();
                  $HTTP_Client_CLI->halt();
               }

               return;
            }
         }

         // @ Response complete — branch by mode
         if (self::$eventDriven) {
            // @ Event-driven: fire hook, reuse connection
            $Request->connectionState = 'idle';

            if (self::$onResponse !== null) {
               (self::$onResponse)($Request, $Request->Response, $receivedNS);
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

               // @ Reuse existing Request object when method+URI match (avoid allocation)
               if ($next->method === $Request->method && $next->URI === $Request->URI) {
                  $Request->pendingBuffer = '';
                  $Request->connectionState = 'waiting';
                  $Request->completed = false;
                  $Request->bytesReceived = 0;
                  // @ Skip Response->reset() — cache hit skips repopulation,
                  // so Response retains correct data from previous cycle
                  // $Request stays in pendingRequests[$socketId]

                  // @ Reuse last encoded output directly (avoids cacheKey concat + hash lookup)
                  static $reusedOutput = null;
                  $Connection->output = $reusedOutput ??= self::$encoderCache[$next->method . ' ' . $next->URI];
               }
               else {
                  $HTTP_Client_CLI->pendingRequests[$socketId] = $next;

                  // @ Use cached encoded output if available
                  $cacheKey = $next->method . ' ' . $next->URI;
                  if (isset(self::$encoderCache[$cacheKey])) {
                     $Connection->output = self::$encoderCache[$cacheKey];
                  }
                  else {
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

                     self::$encoderCache[$cacheKey] = $Connection->output;
                  }
               }

               self::$Event->del($Socket, self::$Event::EVENT_READ);
               self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);
            }
         }
         else {
            // @ Sync/batch: mark complete, release the connection to the pool
            $Request->completed = true;
            $Request->connectionState = 'idle';
            unset($HTTP_Client_CLI->pendingRequests[$socketId]);

            if ($Request->onComplete !== null) {
               ($Request->onComplete)($Request);
            }

            if ($Request->Response->closeConnection) {
               // @ close() fires the disconnect hook: pool drop + promote + halt
               $Connection->close();
            }
            else {
               // @ Park the connection idle — it leaves the select sets;
               //   liveness is re-checked at acquire()
               self::$Event->del($Socket, self::$Event::EVENT_READ);
               $HTTP_Client_CLI->Pool->release($Connection);
            }

            // @ Feed queued requests into the freed capacity
            $HTTP_Client_CLI->promote();

            // ? Stop the loop when no pending work remains
            $HTTP_Client_CLI->halt();
         }
      };

      // @ After write completes, switch to read mode
      self::$onDataWrite = function ($Socket, $Connection) use ($HTTP_Client_CLI) {
         self::$Event->del($Socket, self::$Event::EVENT_WRITE);
         self::$Event->add($Socket, self::$Event::EVENT_READ, $Connection);

         if (self::$httpOnWrite !== null) {
            // ? The third argument is the exact logical Request whose final
            //   encoded byte just reached the socket API. Existing callbacks
            //   that declare fewer arguments remain valid in PHP.
            $Request = $HTTP_Client_CLI->pendingRequests[$Connection->id] ?? null;
            (self::$httpOnWrite)($Socket, $Connection, $Request);
         }
      };

      // @ On transport EOF: a LEGAL close-delimited body (RFC 7230 §3.3.3 —
      //   no Content-Length, no chunked framing) is terminated by the
      //   connection close itself, so the response is finalized here instead
      //   of dying in the waiting decoder until the deadline. A chunked or
      //   Content-Length body cut short by EOF is TRUNCATED, not legal — it
      //   fails immediately while retaining its incomplete-body metadata.
      parent::$onClientDisconnect = function ($Connection) use ($HTTP_Client_CLI) {
         $completedNS = (int) hrtime(true);
         // @ Pool bookkeeping first — the connection is gone either way
         if (self::$eventDriven === false) {
            $HTTP_Client_CLI->Pool->drop($Connection);
         }

         // # HTTP/2: fail every in-flight stream of the dead Session
         $Session = $HTTP_Client_CLI->Sessions[$Connection->id] ?? null;
         if ($Session !== null) {
            unset($HTTP_Client_CLI->Sessions[$Connection->id]);

            foreach ($HTTP_Client_CLI->pendingStreams[$Connection->id] ?? [] as $PendingRequest) {
               $PendingRequest->Response->code = 0;
               $PendingRequest->Response->status = 'Connection Closed';
               $PendingRequest->completed = true;
               $PendingRequest->connectionState = 'idle';

               if ($PendingRequest->onComplete !== null) {
                  ($PendingRequest->onComplete)($PendingRequest);
               }
            }
            unset($HTTP_Client_CLI->pendingStreams[$Connection->id]);

            $HTTP_Client_CLI->promote();
            $HTTP_Client_CLI->halt();

            return;
         }

         $Request = $HTTP_Client_CLI->pendingRequests[$Connection->id] ?? null;
         if ($Request !== null) {
            $Response = $Request->Response;
            $closeDelimited =
               $Connection->peerEOF
               && $Response->code > 0
               && $Response->Body->waiting
               && $Request->Decoder instanceof Decoder_Chunked === false
               && $Response->Body->length === $Response->Body->downloaded;

            if ($closeDelimited) {
               // ! A LEGAL close-delimited body (RFC 7230 §3.3.3 — no
               //   Content-Length, no chunked framing) is terminated by the
               //   connection close itself.
               $Response->Body->waiting = false;
               $Request->completed = true;
               $Request->connectionState = 'idle';
               unset($HTTP_Client_CLI->pendingRequests[$Connection->id]);

               if (self::$eventDriven && self::$onResponse !== null) {
                  (self::$onResponse)($Request, $Response, $completedNS);
               }
               else if ($Request->onComplete !== null) {
                  ($Request->onComplete)($Request);
               }
            }
            else if (
               self::$eventDriven === false
               && $Request->reused
               && $Request->replayed === false
               && $Request->bytesReceived === 0
            ) {
               // ! Stale-reuse replay (keep-alive race): a request dispatched on
               //   a REUSED pooled connection that died before ANY response byte
               //   was provably never processed — transparently re-dispatch it
               //   once. Method-agnostic; does not consume maxRetries.
               $Request->replayed = true;
               $Request->reused = false;
               unset($HTTP_Client_CLI->pendingRequests[$Connection->id]);

               $Request->pendingBuffer = '';
               $Request->Response->reset();
               $Request->Decoder = new Decoder_;
               $Request->connectionState = 'waiting';
               $Request->completed = false;

               $Replacement = $HTTP_Client_CLI->Pool->acquire();
               $redispatched = $Replacement !== null
                  && $HTTP_Client_CLI->dispatch($Request, $Replacement);

               if ($redispatched === false
                  && $HTTP_Client_CLI->Pool->created < $HTTP_Client_CLI->Pool->max
               ) {
                  $HTTP_Client_CLI->nextRequest = $Request;
                  $redispatched = $HTTP_Client_CLI->connect() !== false;
                  if ($redispatched === false) {
                     $HTTP_Client_CLI->nextRequest = null;
                  }
               }
               else if (
                  $redispatched === false
                  && $HTTP_Client_CLI->Pool->created >= $HTTP_Client_CLI->Pool->max
               ) {
                  // ? Pool momentarily full — queue for the next promotion
                  $HTTP_Client_CLI->Queue[] = $Request;
                  $HTTP_Client_CLI->watch($Request);
                  $redispatched = true;
               }

               if ($redispatched === false) {
                  // ? Replay dial failed — surface the transport failure
                  $Response->code = 0;
                  $Response->status = 'Connection Closed';
                  $Request->completed = true;
                  $Request->connectionState = 'idle';

                  if ($Request->onComplete !== null) {
                     ($Request->onComplete)($Request);
                  }
               }
            }
            else {
               // ! EOF before a declared Content-Length/chunk terminator (or
               //   before complete headers) is a transport failure, never a
               //   successful close-delimited response. A non-EOF close with a
               //   pending request fails fast instead of waiting for a timeout.
               $Response->code = 0;
               $Response->status = match (true) {
                  $Connection->peerEOF === false => 'Connection Lost',
                  $Response->status === '' => 'Connection Closed',
                  default => 'Truncated Response'
               };
               $Request->completed = true;
               $Request->connectionState = 'idle';
               unset($HTTP_Client_CLI->pendingRequests[$Connection->id]);

               if (self::$eventDriven && self::$onResponse !== null) {
                  (self::$onResponse)($Request, $Response, $completedNS);
               }
               else if ($Request->onComplete !== null) {
                  ($Request->onComplete)($Request);
               }
            }
         }

         // @ Feed queued requests into the freed capacity
         if (self::$eventDriven === false) {
            $HTTP_Client_CLI->promote();
         }

         // ? Stop the loop when no pending work remains
         $HTTP_Client_CLI->halt();
      };
   }

   /**
   * Resolve a redirect Location header value to host/port/path/secure.
    *
    * @param string $location The Location header value.
    * @param string $currentURI The current request URI (for relative resolution).
    *
    * @return array{host: string, port: int, path: string, secure: bool}
    */
   private function resolve (string $location, string $currentURI): array
   {
      $host = self::$targetHost ?? '127.0.0.1';
      $port = self::$targetPort ?? 80;
      $secure = $this->secure !== null;

      $parsed = parse_url($location);

      if ($parsed === false) {
         return ['host' => $host, 'port' => $port, 'path' => $location, 'secure' => $secure];
      }

      // @ Absolute URL (has scheme + host)
      if (isset($parsed['scheme']) && isset($parsed['host'])) {
         $host = $parsed['host'];
         $secure = ($parsed['scheme'] === 'https');
         $port = $parsed['port'] ?? ($secure ? 443 : 80);
         $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

         return ['host' => $host, 'port' => $port, 'path' => $path, 'secure' => $secure];
      }

      // @ Absolute path
      if (isset($parsed['path']) && ($parsed['path'][0] ?? '') === '/') {
         $path = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

         return ['host' => $host, 'port' => $port, 'path' => $path, 'secure' => $secure];
      }

      // @ Relative path: resolve against current URI's directory
      $currentDir = '/';
      $lastSlash = strrpos($currentURI, '/');
      if ($lastSlash !== false) {
         $currentDir = substr($currentURI, 0, $lastSlash + 1);
      }
      $path = $currentDir . $location;

      return ['host' => $host, 'port' => $port, 'path' => $path, 'secure' => $secure];
   }

   /**
    * Encode and dispatch a Request on an established Connection.
    *
    * @param Request $Request The per-connection Request (decode state holder).
    * @param Connection $Connection The transport connection.
    * @param null|Request $Template Encode source when the Request is a
    * per-socket clone (event-driven mode); defaults to the Request itself.
    *
    * @return void
    */
   private function send (Request $Request, Connection $Connection, null|Request $Template = null): void
   {
      // !
      $Template ??= $Request;
      $Socket = $Connection->Socket;

      $this->pendingRequests[$Connection->id] = $Request;

      // @ Track when request was sent for timeout detection
      $Request->sentAt = microtime(true);

      // @ Use cached encoded output if available (event-driven, same method+URI)
      $cacheKey = "{$Template->method} {$Template->URI}";
      if (self::$eventDriven && isset(self::$encoderCache[$cacheKey])) {
         $Connection->output = self::$encoderCache[$cacheKey];
      }
      else {
         $headerRaw = $Template->Header->build();
         $length = null;

         // @ Detect Expect: 100-continue — send headers only, defer body
         if (stripos($headerRaw, 'Expect: 100-continue') !== false
            && $Template->Body->raw !== ''
         ) {
            $Connection->output = self::$Encoder::encode(
               $Template->method,
               $Template->URI,
               $Template->protocol,
               $headerRaw,
               host: self::$targetHost ?? '127.0.0.1',
               port: self::$targetPort ?? 80,
               length: $length
            );
            $Request->connectionState = 'waiting-100-continue';
         }
         else {
            $Connection->output = self::$Encoder::encode(
               $Template->method,
               $Template->URI,
               $Template->protocol,
               $headerRaw,
               $Template->Body->raw,
               self::$targetHost ?? '127.0.0.1',
               self::$targetPort ?? 80,
               $length
            );
         }

         // @ Cache encoded output for reuse
         if (self::$eventDriven) {
            self::$encoderCache[$cacheKey] = $Connection->output;
         }
      }

      self::$Event->del($Socket, self::$Event::EVENT_READ);
      self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);

      // @ Every dispatch arms its own response deadline (sync/batch)
      if (self::$eventDriven === false) {
         $this->watch($Request);
      }
   }

   /**
    * Arm the response deadline for a dispatched request.
    *
    * Every dispatch (initial send, retry, replay, redirect leg, queue
    * promotion) gets its own response window — bounded additionally by the
    * caller-supplied absolute deadlines. A firing timer withdraws the
    * request from every pipeline stage: overflow queue, h1 connection,
    * h2 stream (sibling streams keep their connection).
    *
    * @param Request $Request The dispatched request.
    *
    * @return void
    */
   private function watch (Request $Request): void
   {
      // ! Combine the response timeout with the caller deadlines
      $deadline = $this->deadline;
      $monotonicDeadline = $this->monotonicDeadline;
      if ($this->timeout > 0) {
         $responseDeadline = microtime(true) + $this->timeout;
         $responseMonotonicDeadline = (int) hrtime(true)
            + (int) ($this->timeout * 1_000_000_000);
         $deadline = $deadline === null
            ? $responseDeadline
            : min($deadline, $responseDeadline);
         $monotonicDeadline = $monotonicDeadline === null
            ? $responseMonotonicDeadline
            : min($monotonicDeadline, $responseMonotonicDeadline);
      }

      // ? Unbounded by configuration
      if ($deadline === null && $monotonicDeadline === null) {
         return;
      }

      /** @var array<int,int> $timers */
      $timers = [];
      $Timeout = function () use ($Request, &$timers): void {
         // @ This callback may be registered against both clocks. Cancel
         //   its sibling before mutating transport state.
         foreach ($timers as $timerID) {
            self::$Event->cancel($timerID);
         }
         $timers = [];

         // ? Already concluded — a late timer is a no-op
         if ($Request->completed) {
            return;
         }

         // @ Timeout: mark request as timed out
         $Request->Response->code = 0;
         $Request->Response->status = 'Timeout';
         $Request->completed = true;
         $Request->connectionState = 'idle';

         // @ Purge from the batch overflow queue
         foreach ($this->Queue as $index => $Queued) {
            if ($Queued === $Request) {
               unset($this->Queue[$index]);
            }
         }

         // @ Close pending h1 connections carrying this request
         foreach ($this->pendingRequests as $socketID => $PendingRequest) {
            if ($PendingRequest === $Request) {
               unset($this->pendingRequests[$socketID]);
               $Connection = Connections::$Connections[$socketID] ?? null;
               // ! close() fires the disconnect hook: pool drop + promote
               $Connection?->close();
            }
         }

         // # HTTP/2: cancel only the timed-out stream — sibling streams
         //   keep the multiplexed connection alive
         foreach ($this->pendingStreams as $socketID => $Streams) {
            foreach ($Streams as $stream => $PendingRequest) {
               if ($PendingRequest !== $Request) {
                  continue;
               }

               unset($this->pendingStreams[$socketID][$stream]);
               if ($this->pendingStreams[$socketID] === []) {
                  unset($this->pendingStreams[$socketID]);
               }

               $Session = $this->Sessions[$socketID] ?? null;
               $Connection = Connections::$Connections[$socketID] ?? null;
               if ($Session !== null && $Connection !== null) {
                  $Session->reset($stream, Errors::Cancel);
                  // @ The local reset record is moot — the request timed out
                  unset($Session->done[$stream]);
                  $this->flush($Session, $Connection);
                  $this->Pool->release($Connection);
               }
            }
         }

         if ($Request->onComplete !== null) {
            ($Request->onComplete)($Request);
         }

         // ? Stop the loop when no pending work remains
         $this->halt();
      };

      if ($deadline !== null) {
         $timers[] = self::$Event->defer($deadline, $Timeout);
      }
      if ($monotonicDeadline !== null) {
         $timers[] = self::$Event->defer($monotonicDeadline, $Timeout);
      }
   }

   /**
    * Open an HTTP/2 stream for a Request on a negotiated Session.
    *
    * @param Session $Session The h2 connection engine.
    * @param int $socketId The connection socket ID.
    * @param Request $Request The request to submit.
    *
    * @return int The allocated stream ID, or 0 when the Session cannot open.
    */
   private function submit (Session $Session, int $socketId, Request $Request): int
   {
      // !
      $scheme = $this->secure !== null ? 'https' : 'http';
      $host = self::$targetHost ?? '127.0.0.1';
      $port = self::$targetPort ?? 80;
      $default = $scheme === 'https' ? 443 : 80;
      $authority = $port === $default ? $host : "{$host}:{$port}";

      // @ Open the stream (HEADERS[+DATA] queued into the Session outbox)
      $stream = $Session->open(
         $Request->method,
         $scheme,
         $authority,
         $Request->URI,
         $Request->headers,
         $Request->Body->raw
      );

      // ? No capacity / closing Session
      if ($stream === 0) {
         return 0;
      }

      $this->pendingStreams[$socketId][$stream] = $Request;
      $Request->sentAt = microtime(true);

      // @ Every dispatch arms its own response deadline
      $this->watch($Request);

      // :
      return $stream;
   }

   /**
    * Ship queued Session frames to the connection output.
    *
    * EVENT_READ stays armed — control frames must flow while reading, or
    * WINDOW_UPDATE exchanges deadlock.
    *
    * @param Session $Session The h2 connection engine.
    * @param Connection $Connection The transport connection.
    *
    * @return void
    */
   private function flush (Session $Session, Connection $Connection): void
   {
      // ? Nothing queued
      if ($Session->outbox === '') {
         return;
      }

      $Connection->output .= $Session->outbox;
      $Session->outbox = '';

      self::$Event->add($Connection->Socket, self::$Event::EVENT_WRITE, $Connection);
   }

   /**
    * Dispatch a Request on a pool-acquired Connection (h1 send or h2 stream).
    *
    * @param Request $Request The request to dispatch.
    * @param Connection $Connection A connection acquired from the pool.
    *
    * @return bool False when the connection could not take the request
    * (it was released back / retired) — the caller must queue or dial.
    */
   private function dispatch (Request $Request, Connection $Connection): bool
   {
      $Session = $this->Sessions[$Connection->id] ?? null;

      // ?: HTTP/1.1 — one request per connection
      if ($Session === null) {
         $Request->reused = true;
         $this->send($Request, $Connection);

         return true;
      }

      // @ HTTP/2 — one stream on the multiplexed Session
      $stream = $this->submit($Session, $Connection->id, $Request);
      if ($stream !== 0) {
         $this->flush($Session, $Connection);

         return true;
      }

      // ? The Session cannot open new streams (GOAWAY / connection error)
      if ($Session->closing || $Session->error !== null) {
         // ! Retire it: cap to the in-flight count so acquire() skips it
         $this->Pool->cap($Connection, max(1, $Session->opened));

         if ($Session->opened === 0) {
            // @ close() fires the disconnect hook: pool drop
            $Connection->close();
         }
         else {
            $this->Pool->release($Connection);
         }

         return false;
      }

      // ? Stream capacity race (pool bookkeeping ahead of the Session) —
      //   release and let the caller queue for a later promotion
      if ($Session->capacity <= 0) {
         $this->Pool->release($Connection);

         return false;
      }

      // ? A healthy Session with spare capacity refusing a stream is a
      //   PERMANENT per-request failure (header list beyond the peer's
      //   cap) — requeueing would loop forever; fail the request instead
      $this->Pool->release($Connection);

      $Request->Response->code = 0;
      $Request->Response->status = 'Request Header Fields Too Large';
      $Request->completed = true;
      $Request->connectionState = 'idle';

      if ($Request->onComplete !== null) {
         ($Request->onComplete)($Request);
      }

      // : The request was consumed (failed) — nothing left to queue
      return true;
   }

   /**
    * Consume inbound bytes on an HTTP/2 connection and route completions.
    *
    * @param Session $Session The h2 connection engine.
    * @param Connection $Connection The transport connection.
    * @param int $socketId The connection socket ID.
    *
    * @return void
    */
   private function receive (Session $Session, Connection $Connection, int $socketId): void
   {
      // !
      $input = $Connection->input;
      $bytes = strlen($input);
      if ($bytes > 0) {
         self::$bytesReceived += $bytes;
      }

      // @ Feed the engine
      $ok = $Session->feed($input);

      // @ Sync the pool stream capacity with the (possibly updated) peer SETTINGS
      $this->Pool->cap($Connection, max(1, $Session->capacity + $Session->opened));

      // @ Route completed exchanges
      if ($Session->done !== []) {
         $done = $Session->done;
         $Session->done = [];

         foreach ($done as $stream => $record) {
            $Request = $this->pendingStreams[$socketId][$stream] ?? null;
            if ($Request === null) {
               continue;
            }

            unset($this->pendingStreams[$socketId][$stream]);
            if (($this->pendingStreams[$socketId] ?? []) === []) {
               unset($this->pendingStreams[$socketId]);
            }

            $this->conclude($Request, $record, $Session, $Connection, $socketId);
         }
      }

      // @ Ship control frames / drained request-body backlog
      $this->flush($Session, $Connection);

      // ? Connection error — the GOAWAY was flushed above (best-effort)
      if ($ok === false) {
         // @ close() fires the disconnect hook: it fails the remaining
         //   pendingStreams, drops from the pool, promotes and halts
         $Connection->close();

         return;
      }

      // @ Feed queued requests into any freed capacity
      $this->promote();

      // ? Stop the loop when no pending work remains
      $this->halt();
   }

   /**
    * Conclude one HTTP/2 exchange: populate the Response, handle redirects
    * and retryable stream errors, release the pool stream.
    *
    * @param Request $Request The concluded request.
    * @param array{stream:int,code:int,headerRaw:string,body:string,error:null|Errors,retryable:bool} $record
    * @param Session $Session The h2 connection engine.
    * @param Connection $Connection The transport connection.
    * @param int $socketId The connection socket ID.
    *
    * @return void
    */
   private function conclude (
      Request $Request,
      array $record,
      Session $Session,
      Connection $Connection,
      int $socketId
   ): void
   {
      $Response = $Request->Response;

      // # Stream error
      if ($record['error'] !== null) {
         $this->Pool->release($Connection);

         // ! A retryable stream (REFUSED_STREAM / past a GOAWAY point) was
         //   provably never processed — transparently re-dispatch it once,
         //   any method, without consuming maxRetries (same contract as the
         //   h1 stale-reuse replay).
         if ($record['retryable'] && $Request->replayed === false) {
            $Request->replayed = true;
            $Request->Response->reset();
            $Request->connectionState = 'waiting';
            $Request->completed = false;

            $Replacement = $this->Pool->acquire();
            if ($Replacement !== null && $this->dispatch($Request, $Replacement)) {
               return;
            }

            if ($this->Pool->created < $this->Pool->max) {
               $this->nextRequest = $Request;
               if ($this->connect() !== false) {
                  return;
               }
               $this->nextRequest = null;
            }
            else {
               // ? Pool momentarily full — queue for the next promotion
               $this->Queue[] = $Request;
               $this->watch($Request);

               return;
            }
         }

         $Response->code = 0;
         $Response->status = "HTTP/2 Stream Error: {$record['error']->name}";
         $Request->completed = true;
         $Request->connectionState = 'idle';

         if ($Request->onComplete !== null) {
            ($Request->onComplete)($Request);
         }

         return;
      }

      // # Response
      $Response->protocol = 'HTTP/2';
      $Response->code = $record['code'];
      $Response->status = ''; // h2 has no reason-phrase
      $Response->closeConnection = false;
      $Response->Header->define($record['headerRaw']);
      $Response->Header->build();
      $Response->Body->raw = $record['body'];
      $Response->Body->length = strlen($record['body']);
      $Response->Body->downloaded = strlen($record['body']);
      $Response->Body->waiting = false;

      // # Redirect (same RFC 7231 rules as the h1 path)
      $code = $Response->code;
      if (
         $this->maxRedirects > 0
         && $Request->redirectCount < $this->maxRedirects
         && ($code === 301 || $code === 302 || $code === 303
            || $code === 307 || $code === 308)
      ) {
         $location = $Response->Header->get('Location');
         if ($location !== null && $location !== '') {
            $Request->redirectCount++;

            if ($Request->originalMethod === '') {
               $Request->originalMethod = $Request->method;
               $Request->originalBody = $Request->Body->raw;
            }

            if ($code === 301 || $code === 302 || $code === 303) {
               if ($Request->method !== 'HEAD') {
                  $Request->method = 'GET';
               }
               $Request->clear();
            }

            $resolved = $this->resolve($location, $Request->URI);
            $Request->URI = $resolved['path'];

            $sameHost = $resolved['host'] === (self::$targetHost ?? '127.0.0.1')
               && $resolved['port'] === (self::$targetPort ?? 80)
               && $resolved['secure'] === ($this->secure !== null);

            if ($sameHost) {
               // @ Same origin: a NEW stream on the same Session (the finished
               //   stream and the new one cancel out in the pool accounting)
               $Response->reset();
               $Request->connectionState = 'waiting';

               if ($this->submit($Session, $socketId, $Request) !== 0) {
                  return;
               }
            }

            // @ Cross-origin (or no capacity): finish here — the sync
            //   follow() loop reconfigures and re-dials
            $Request->connectionState = 'redirect';
            $Request->redirectTarget = $resolved;
         }
      }

      // # Completion
      $this->Pool->release($Connection);

      $Request->completed = true;
      if ($Request->connectionState !== 'redirect') {
         $Request->connectionState = 'idle';
      }

      if ($Request->onComplete !== null) {
         ($Request->onComplete)($Request);
      }
   }

   /**
    * Warm the pool up to its configured minimum (lazy, first request only).
    *
    * @return void
    */
   private function warm (): void
   {
      // ? Warm-up runs once per configuration, sync/batch modes only
      if ($this->warmed || self::$eventDriven) {
         return;
      }
      $this->warmed = true;

      // @@ Dial the configured minimum sequentially (blocking TCP+TLS)
      while ($this->Pool->created < $this->Pool->min) {
         $this->nextRequest = null; // idle attach

         $before = $this->Pool->created;
         if ($this->connect() === false || $this->Pool->created === $before) {
            $this->Pool->penalize();
            break;
         }
      }
   }

   /**
    * Feed queued requests into freed pool capacity.
    *
    * @return void
    */
   private function promote (): void
   {
      // @@
      $attempts = 0;
      while ($this->Queue !== []) {
         $Connection = $this->Pool->acquire();
         if ($Connection !== null) {
            $Request = array_shift($this->Queue);
            if ($this->dispatch($Request, $Connection) === false) {
               // ? Retired connection / capacity race — put it back; retry
               //   within a bounded number of passes (retired connections
               //   leave the pool, so each pass makes progress)
               array_unshift($this->Queue, $Request);
               if (++$attempts > $this->Pool->max) {
                  break;
               }
            }

            continue;
         }

         if ($this->Pool->created < $this->Pool->max) {
            $Request = array_shift($this->Queue);
            $this->nextRequest = $Request;

            if ($this->connect() === false) {
               // ? Dial failed — surface the failure and try the next queued
               $this->nextRequest = null;
               $Request->Response->code = 0;
               $Request->Response->status = 'Connection Failed';
               $Request->completed = true;

               if ($Request->onComplete !== null) {
                  ($Request->onComplete)($Request);
               }
            }

            continue;
         }

         // ? No capacity — wait for the next release
         break;
      }
   }

   /**
    * Halt the event loop when no pending client work remains.
    *
    * @return void
    */
   protected function halt (): void
   {
      // ? Pending work keeps the loop running
      if (
         $this->pendingRequests !== []
         || $this->pendingStreams !== []
         || $this->Queue !== []
         || $this->retrying > 0
         || $this->dialing > 0
      ) {
         return;
      }

      self::$Event->destroy();
   }

   /**
    * Follow cross-origin redirects (sync mode).
    *
    * @param Request $Request The redirected request.
    *
    * @return void
    */
   private function follow (Request $Request): void
   {
      // @@
      /** @phpstan-ignore identical.alwaysFalse, booleanAnd.alwaysFalse */
      while ($Request->connectionState === 'redirect' && $Request->redirectTarget !== null) {
         $resolved = $Request->redirectTarget;
         $Request->redirectTarget = null;
         $Request->Response->reset();
         $Request->connectionState = 'waiting';
         $Request->completed = false;
         $Request->bytesReceived = 0;
         $Request->reused = false;

         // @ Reconfigure for the new target (retires the previous origin's pool)
         $this->configure(
            $resolved['host'],
            $resolved['port'],
            secure: $resolved['secure'] ? ['peer_name' => $resolved['host']] : null
         );

         $this->nextRequest = $Request;
         $this->wire();

         $Socket = $this->connect();
         if ($Socket === false) {
            break;
         }

         $this->drain();
      }
   }

   /**
    * Compute the next retry backoff delay for a request.
    *
    * Capped exponential backoff + proportional jitter; a Retry-After response
    * header (delta-seconds or HTTP-date) may extend the wait; the wall-clock
    * campaign budget may veto the retry entirely.
    *
    * @param Request $Request The failed request.
    * @param null|Response $Response Response carrying Retry-After (HTTP-level retry).
    *
    * @return null|float Delay in seconds, or null to give up.
    */
   private function delay (Request $Request, null|Response $Response = null): null|float
   {
      // ! Capped exponential backoff
      $delay = min(
         (float) $this->retryMaxDelay,
         (float) $this->retryDelay * (2 ** min($Request->retryCount, 16))
      );
      // ! Proportional jitter
      if ($this->retryJitter > 0) {
         $delay += $delay * $this->retryJitter * (mt_rand(0, 1000) / 1000);
      }

      // @ Retry-After may extend the wait (server-solicited pacing)
      if ($Response !== null) {
         $after = $this->parse($Response->Header->get('Retry-After'));
         if ($after !== null) {
            $delay = max($delay, (float) $after);
         }
      }

      // ? Wall-clock campaign budget (stamped at the first failure)
      if ($this->retryTimeout > 0) {
         if ($Request->retryStartedAt === 0.0) {
            $Request->retryStartedAt = microtime(true);
         }

         $elapsed = microtime(true) - $Request->retryStartedAt;
         if ($elapsed + $delay > $this->retryTimeout) {
            return null;
         }
      }

      // :
      return $delay;
   }

   /**
    * Parse a Retry-After header value (RFC 9110 §10.2.3).
    *
    * @param null|string $value Delta-seconds or IMF-fixdate.
    *
    * @return null|int Seconds to wait (clamped), or null when absent/invalid.
    */
   private function parse (null|string $value): null|int
   {
      // ?
      if ($value === null || $value === '') {
         return null;
      }

      // ?: Delta-seconds form
      if (ctype_digit($value)) {
         return min((int) $value, self::MAX_RETRY_AFTER);
      }

      // ?: HTTP-date form
      $timestamp = strtotime($value);
      if ($timestamp === false) {
         return null;
      }

      // :
      return min(max(0, $timestamp - time()), self::MAX_RETRY_AFTER);
   }

   /**
    * Handle retry for a failed request (sync/batch modes).
    *
    * Network-failure retries stay idempotent-only; HTTP-level retries
    * ($http = true, opt-in via $retryOn) are server-solicited and allowed
    * for any method. The re-dispatch is SCHEDULED on the event loop with a
    * capped exponential backoff — never a blocking sleep.
    *
    * @param Request $Request The failed request.
    * @param bool $http Whether this is an HTTP-level (status-based) retry.
    *
    * @return bool True if a retry was scheduled.
    */
   private function retry (Request $Request, bool $http = false): bool
   {
      // ? Retry budget
      if ($this->maxRetries <= 0 || $Request->retryCount >= $this->maxRetries) {
         return false;
      }

      // ? Deterministic failures never become transient by retrying
      if (
         $Request->Response->status === 'Response Too Large'
         || $Request->Response->status === 'Request Header Fields Too Large'
      ) {
         return false;
      }

      // ? Network-failure retries: only idempotent methods (or never-sent requests)
      if ($http === false) {
         $method = strtoupper($Request->method);
         $idempotent = in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'], true);

         if (!$idempotent && $Request->sentAt > 0) {
            return false;
         }
      }

      // ? Backoff delay vetoed by the campaign budget
      $delay = $this->delay($Request, $http ? $Request->Response : null);
      if ($delay === null) {
         return false;
      }

      // ! Reset the request transport state
      $Request->retryCount++;
      $Request->pendingBuffer = '';
      $Request->Response->reset();
      $Request->Decoder = new Decoder_;
      $Request->connectionState = 'waiting';
      $Request->completed = false;
      $Request->bytesReceived = 0;
      $Request->reused = false;

      // @ Schedule the re-dispatch on the event loop
      $this->retrying++;
      self::$Event->defer(microtime(true) + $delay, function () use ($Request): void {
         $this->retrying--;

         $Connection = $this->Pool->acquire();
         if ($Connection !== null && $this->dispatch($Request, $Connection)) {
            return;
         }

         if ($this->Pool->created < $this->Pool->max) {
            $this->nextRequest = $Request;
            if ($this->connect() !== false) {
               return;
            }
            $this->nextRequest = null;
         }
         else {
            // ? Pool momentarily full — queue for the next promotion
            $this->Queue[] = $Request;
            $this->watch($Request);

            return;
         }

         // ? Dial failed — the request stays failed
         $Request->completed = true;
         if ($Request->Response->code === 0 && $Request->Response->status === '') {
            $Request->Response->status = 'Connection Failed';
         }
         if ($Request->onComplete !== null) {
            ($Request->onComplete)($Request);
         }
         $this->halt();
      });

      return true;
   }

   /**
    * Run the event loop until all pending requests complete.
    *
    * @return void
    */
   public function drain (): void
   {
      // ? Nothing to drain
      if (
         $this->pendingRequests === []
         && $this->pendingStreams === []
         && $this->Queue === []
         && $this->retrying === 0
         && $this->dialing === 0
      ) {
         return;
      }

      // @ Re-arm the persistent reactor (a previous drain may have stopped it)
      self::$Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
      self::$Event->loop();

      // @ Reset for next batch of requests
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
      // @ Event-driven mode: reuse cached Request when method+URI match (avoid allocation)
      if (self::$eventDriven
         && $this->cachedRequest !== null
         && $this->cachedRequest->method === $method
         && $this->cachedRequest->URI === $URI
         && $headers === []
         && $body === null
      ) {
         $this->nextRequest = $this->cachedRequest;
         return $this;
      }

      // @ Create and prepare request with transport state
      $Request = new Request;
      $Request($method, $URI, $headers, $body);
      $Request->connectionState = 'waiting';

      $this->nextRequest = $Request;

      // @ Event-driven mode: cache and return self
      if (self::$eventDriven) {
         $this->cachedRequest = $Request;
         $this->wire();
         return $this;
      }

      // @ Sync/batch mode — the reactor is persistent (constructor-built)
      $this->wire();

      // @ Warm the pool minimum, then acquire an idle pooled connection or dial
      $this->warm();

      // @@ Reuse a pooled connection (h1 keep-alive or h2 stream) — a
      //    dispatch may retire a stale/GOAWAY'd connection and report
      //    false; each bounded retry then makes progress (the pool shrank)
      $placed = false;
      for ($attempt = 0; $attempt <= $this->Pool->max; $attempt++) {
         $Connection = $this->Pool->acquire();
         if ($Connection === null) {
            break;
         }

         $this->nextRequest = null;
         if ($this->dispatch($Request, $Connection)) {
            $placed = true;
            break;
         }
      }

      if ($placed === false && $this->Pool->created < $this->Pool->max) {
         // @ Connect (fires connect callback which sends the request)
         $this->nextRequest = $Request;
         $Socket = $this->connect();
         if ($Socket === false) {
            $this->nextRequest = null;

            // @ Retry on connection failure — a scheduled retry continues
            //   through the normal drain/redirect/retry pipeline below
            if ($this->retry($Request) === false) {
               $Request->completed = true;

               return $Request->Response;
            }
         }
      }
      else if ($placed === false) {
         // @ Pool exhausted — queue until a connection frees (batch overflow)
         $this->nextRequest = null;
         $this->Queue[] = $Request;
         // ! Queued requests are not dispatched yet — bound their wait too
         $this->watch($Request);
      }

      // @ Batch mode: return Response reference (filled later by drain())
      if ($this->batching) {
         return $Request->Response;
      }

      // @ Sync mode: drain and return completed Response
      $this->drain();

      // @ Follow cross-origin redirects (sync mode only)
      $this->follow($Request);

      // @ Retry if failed (timeout or connection reset) — capped by maxRetries
      while ($Request->Response->code === 0 && $this->retry($Request)) {
         $this->drain();
         $this->follow($Request);
      }

      // @ HTTP-level retry (opt-in via $retryOn, e.g. 429/503) — honors Retry-After
      while (
         $this->retryOn !== []
         && in_array($Request->Response->code, $this->retryOn, true)
         && $this->retry($Request, http: true)
      ) {
         $this->drain();
         $this->follow($Request);
      }

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
   * @param array<string,mixed>|null $secure Secure SSL/TLS context for mock server (local_cert, local_pk, etc.)
    *
    * @return bool True if tests completed.
    */
   public static function test (int $port = 9999, null|array $secure = null): bool
   {
      Display::show(Display::NONE);

      // @ Start mock TCP server in background (fork)
      $process_id = pcntl_fork();
      if ($process_id === -1) {
         return false;
      }

      // # Server
      if ($process_id === 0) {
         // @ Child process: run mock TCP server
         // @ Create SSL context for server if needed
         if ($secure !== null) {
            $serverContext = stream_context_create(['ssl' => $secure]);
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
            if ($secure !== null) {
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

            // @@ Serve requests on this connection (keep-alive specs reuse it)
            $serve = true;
            while ($serve) {
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

               // ? Peer closed between keep-alive requests
               if ($input === '') {
                  break;
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

               // ?: Keep the connection open while a keep-alive spec still has
               //    responses queued for it
               $serve = $spec instanceof E2ESpecification
                  && $spec->keepAlive
                  && $requestIndex > 0;
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
      self::testing($port, $secure !== null ? [
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
   * @param array<string,mixed>|null $secure Secure SSL/TLS context options for the client.
    *
    * @return void
    */
   protected static function testing (int $port, null|array $secure = null): void
   {
      Display::show(Display::MESSAGE);

      $Suite = CAPI::$Suite;
      $Suite->separate($Suite->name);

      $testFiles = CAPI::$tests[self::class] ?? [];
      $specIndex = 0;

      // @ Create a single reusable HTTP client instance (lightweight test mode)
      $HTTP_Client_CLI = new self(self::MODE_TEST);
      $HTTP_Client_CLI->configure('127.0.0.1', $port, secure: $secure);

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
         }
         else {
            // @ Single-request test
            $requestClosure = $spec->request;
            $responses[] = $requestClosure($HTTP_Client_CLI);
         }

         $specIndex++;

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
            $Test->test(...$responses);
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

      Display::show(Display::MESSAGE);
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use function is_array;
use function is_string;
use function strcspn;
use function strlen;
use function strspn;

use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;


class Request
{
   public protected(set) Header $Header;
   public protected(set) Body $Body;


   // * Config
   // ...

   // * Data
   // | HTTP Request
   public string $method;
   public string $URI;
   public string $protocol;
   /**
    * @var array<string,string|array<int,string>>
    */
   public array $headers {
      get => $this->Header->fields;
   }
   public string $body {
      get => $this->Body->raw;
   }
   // | Transport
   public Response $Response;
   /** @var Decoder */
   public Decoder $Decoder;

   // * Metadata
   /** RFC 9110 §5.6.2 token alphabet accepted for extension methods too. */
   private const string METHOD =
      "!#$%&'*+-.^_`|~"
      . '0123456789'
      . 'abcdefghijklmnopqrstuvwxyz'
      . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
   /** C0, SP, DEL, backslash and fragment marker never enter a request-target. */
   private const string TARGET_INVALID =
      "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F"
      . "\x20\x7F\\#";
   // | Transport
   public string $pendingBuffer;
   /** Connection state: 'idle' | 'waiting' | 'waiting-100-continue' | 'redirect' */
   public string $connectionState;
   public bool $completed;
   public int $bytesReceived;
   // | Encoder
   /** Encoded wire bytes memoized for re-dispatch, or null when stale. */
   public null|string $encoded;
   /** Origin host the memoized encoding was built for. */
   public null|string $encodedHost;
   /** Origin port the memoized encoding was built for. */
   public null|int $encodedPort;
   // | Redirect
   public int $redirectCount;
   public string $originalMethod;
   public string $originalBody;
   /** @var array{host:string,port:int,path:string,secure:bool}|null */
   public null|array $redirectTarget;
   // | Timeout
   public float $sentAt;
   /**
    * Response-deadline timer IDs armed for the current dispatch, one per clock
    * domain. Withdrawn when the request is re-dispatched or concludes.
    *
    * @var array<int,int>
    */
   public array $timers;
   // | Pool
   /** Whether this request was dispatched on a reused (pooled) connection. */
   public bool $reused;
   /** Whether the stale-reuse replay was already consumed. */
   public bool $replayed;
   // | Retry
   public int $retryCount;
   /** Wall-clock start of the retry campaign (0.0 = no retry yet). */
   public float $retryStartedAt;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      // ...

      // * Data
      $this->method = 'GET';
      $this->URI = '/';
      $this->protocol = 'HTTP/1.1';

      // | Transport
      $this->Response = new Response;
      $this->Decoder = new Decoder_;

      // * Metadata
      $this->pendingBuffer = '';
      $this->connectionState = 'idle';
      $this->completed = false;
      $this->bytesReceived = 0;
      // | Encoder
      $this->encoded = null;
      $this->encodedHost = null;
      $this->encodedPort = null;
      // | Redirect
      $this->redirectCount = 0;
      $this->originalMethod = '';
      $this->originalBody = '';
      $this->redirectTarget = null;
      // | Timeout
      $this->sentAt = 0.0;
      $this->timers = [];
      // | Pool
      $this->reused = false;
      $this->replayed = false;
      // | Retry
      $this->retryCount = 0;
      $this->retryStartedAt = 0.0;
   }

   /**
    * Check whether values can form a safe outbound HTTP request-line or the
    * equivalent HTTP/2 `:method` / `:path` pseudo-headers.
    *
    * The client contract is origin-form (`/path?query`) plus the RFC asterisk
    * form for `OPTIONS *`. Absolute/authority forms are intentionally absent:
    * this client connects to the origin configured on the node, not a proxy.
    *
    * @return bool True when all supplied request-line values are valid.
    */
   public static function check (
      string $method,
      string $URI,
      null|string $protocol = null
   ): bool
   {
      if (
         $method === ''
         || strspn($method, self::METHOD) !== strlen($method)
      ) {
         return false;
      }

      if ($URI === '*') {
         if ($method !== 'OPTIONS') {
            return false;
         }
      }
      else if (
         $URI === ''
         || $URI[0] !== '/'
         || strcspn($URI, self::TARGET_INVALID) !== strlen($URI)
      ) {
         return false;
      }

      return $protocol === null
         || $protocol === 'HTTP/1.1'
         || $protocol === 'HTTP/1.0';
   }

   /**
    * Prepare the Request with method, URI, and optional headers/body.
    *
    * @param string $method HTTP token method (standard or extension).
    * @param string $URI Origin-form path/query, or `*` with OPTIONS.
    * @param array<string,string> $headers Additional headers to set.
    * @param mixed $body Request body (string, array for JSON, or null).
    *
    * @return self
    * @throws InvalidArgumentException When method, URI or protocol is unsafe.
    */
   public function __invoke (
      string $method = 'GET',
      string $URI = '/',
      array $headers = [],
      mixed $body = null
   ): self
   {
      // ? Reject atomically: invalid request-line values must not change the
      //   Request, invalidate a valid memo or reach either transport encoder.
      if (self::check($method, $URI, $this->protocol) === false) {
         throw new InvalidArgumentException('Invalid HTTP client request-line.');
      }

      // ! Any of method, URI, headers or body may change here, so whatever was
      //   encoded from this Request before no longer describes it
      $this->encoded = null;

      $this->method = $method;
      $this->URI = $URI;

      // @ Set headers
      foreach ($headers as $name => $value) {
         $this->Header->set($name, $value);
      }

      // @ Set body
      if ($body !== null) {
         if (is_string($body)) {
            $this->Body->encode($body);
            if ($this->Header->get('Content-Type') === null) {
               $this->Header->set('Content-Type', 'text/plain');
            }
         }
         else if (is_array($body)) {
            $this->Body->encode($body, 'json');
            if ($this->Header->get('Content-Type') === null) {
               $this->Header->set('Content-Type', 'application/json');
            }
         }

         $this->Header->set('Content-Length', (string) $this->Body->length);
      }

      return $this;
   }

   /**
    * Reset request state for reuse.
    *
    * @return void
    */
   public function reset (): void
   {
      $this->Header = new Header;
      $this->Body = new Body;

      $this->method = 'GET';
      $this->URI = '/';

      // | Transport
      $this->Response->reset();
      $this->Decoder = new Decoder_;

      $this->pendingBuffer = '';
      $this->connectionState = 'idle';
      $this->completed = false;
      $this->bytesReceived = 0;
      // | Encoder
      $this->encoded = null;
      $this->encodedHost = null;
      $this->encodedPort = null;
      // | Redirect
      $this->redirectCount = 0;
      $this->originalMethod = '';
      $this->originalBody = '';
      $this->redirectTarget = null;
      // | Timeout
      $this->sentAt = 0.0;
      $this->timers = [];
      // | Pool
      $this->reused = false;
      $this->replayed = false;
      // | Retry
      $this->retryCount = 0;
      $this->retryStartedAt = 0.0;
   }

   /**
    * Clear request headers and body (used for redirect method change).
    *
    * @return void
    */
   public function clear (): void
   {
      $this->encoded = null;

      $this->Header = new Header;
      $this->Body = new Body;
   }
}

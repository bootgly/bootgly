<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use Closure;
use Fiber;
use LogicException;
use RuntimeException;

use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for awaiting outbound HTTP calls on the worker reactor.
 *
 * Wraps one native `HTTP_Client_CLI` embedded on the server reactor: every
 * wait parks the deferred Fiber instead of pumping a private event loop, so
 * the worker keeps serving its other connections while the upstream answers.
 *
 * Register it once and call it from `defer()`:
 *
 * ```php
 * $HTTP_Server_CLI->configure(responseResources: [
 *    'Upstream' => static fn (object $Context): HTTP => new HTTP(host: 'api.example.com', port: 443, secure: [])
 * ]);
 *
 * $Response->defer(function (Response $Response) {
 *    $Upstream = $Response->Upstream->request(method: 'GET', URI: '/users/1');
 *    $Response->JSON->send(['code' => $Upstream->code]);
 * });
 * ```
 */
class HTTP extends Resource implements Scheduling
{
   // * Config
   // ...

   // * Data
   /**
    * The embedded client. Every knob not covered by the constructor
    * (`retryOn`, `retryDelay`, `maxResponseBytes`, ...) is set here.
    */
   public private(set) HTTP_Client_CLI $Client;

   // * Metadata
   // ! The deferred context that owns this resource for one generation. A
   //   definition-backed instance is rebuilt per defer(), but a plain capture
   //   (or a mount() without a definition) can carry the same object across
   //   contexts — and a parked drain answers only the Fiber that parked it.
   /** @var null|Fiber<mixed,mixed,mixed,mixed> */
   private null|Fiber $Fiber = null;


   /**
    * @param string $host Upstream host.
    * @param int $port Upstream port.
    * @param array<string,mixed>|null $secure TLS stream context options (`[]` enables TLS with the defaults).
    * @param array<string,int>|null $pool Connection pool bounds: `['min' => N, 'max' => N]`.
    * @param int|float $timeout Response timeout in seconds (0 = no timeout).
    * @param int|float $connectTimeout Connection timeout in seconds (0 = no timeout).
    * @param int $maxRedirects Maximum redirects to follow (0 = disabled).
    * @param int $maxRetries Maximum retries on connection/timeout failure (0 = disabled).
    * @param null|bool $enableHTTP2 HTTP/2 negotiation (null = ALPN when secure; true = also h2c; false = never).
    */
   public function __construct (
      string $host,
      int $port = 80,
      null|array $secure = null,
      null|array $pool = null,
      int|float $timeout = 30,
      int|float $connectTimeout = 30,
      int $maxRedirects = 10,
      int $maxRetries = 0,
      null|bool $enableHTTP2 = null
   )
   {
      parent::__construct();

      // ? The client parks on the worker reactor — outside a server there is
      //   nothing to park on
      if (isSet(TCP_Server_CLI::$Event) === false) {
         throw new RuntimeException('HTTP response resource requires the HTTP server reactor — construct it from a responseResources factory.');
      }

      // * Data
      // ! One fresh client per instance, never a prototype clone: a shallow
      //   copy would share its pool, connection registry and hook closures
      //   across deferrals
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
      $Client->react(TCP_Server_CLI::$Event);
      $Client->configure(
         host: $host,
         port: $port,
         secure: $secure,
         pool: $pool,
         enableHTTP2: $enableHTTP2
      );
      $Client->timeout = $timeout;
      $Client->connectTimeout = $connectTimeout;
      $Client->maxRedirects = $maxRedirects;
      $Client->maxRetries = $maxRetries;

      $this->Client = $Client;
   }

   /**
    * Bind the response wait bridge.
    */
   public function schedule (Closure $Wait): static
   {
      $this->Client->schedule($Wait);

      return $this;
   }

   /**
    * Send one HTTP request through the embedded client.
    *
    * Outside `batch()` the deferred Fiber parks until the response completes.
    * Inside it the returned Response is filled later, by `drain()`.
    *
    * @param string $method HTTP method.
    * @param string $URI Request URI.
    * @param array<string,string> $headers Additional headers.
    * @param mixed $body Request body.
    */
   public function request (
      string $method = 'GET',
      string $URI = '/',
      array $headers = [],
      mixed $body = null
   ): Response
   {
      $this->own();

      $Response = $this->Client->request($method, $URI, $headers, $body);

      // ? Event-driven mode is barred under reactor adoption — request()
      //   always yields the Response here
      if ($Response instanceof Response === false) {
         throw new LogicException('HTTP response resource client left synchronous mode.');
      }

      return $Response;
   }

   /**
    * Enter batch mode: subsequent `request()` calls are dispatched
    * concurrently and settled together by `drain()`.
    */
   public function batch (): static
   {
      $this->own();
      $this->Client->batch();

      return $this;
   }

   /**
    * Park the deferred Fiber until every batched request completes.
    */
   public function drain (): static
   {
      $this->own();
      $this->Client->drain();

      return $this;
   }

   /**
    * Claim this resource for the running deferred context.
    */
   private function own (): void
   {
      $Fiber = Fiber::getCurrent();

      // ? A parked wait needs a deferred Fiber to park
      if ($Fiber === null) {
         throw new LogicException('HTTP response resource must be used inside a deferred context — call it from defer().');
      }

      // ?: Already claimed by this context
      if ($this->Fiber === $Fiber) {
         return;
      }

      // ? One context at a time: another Fiber's parked drain would answer
      //   this one's requests, or none at all
      if ($this->Fiber !== null) {
         throw new LogicException('HTTP response resource is owned by another deferred context.');
      }

      // ! The generation token proves the Fiber is a live deferred context —
      //   and it is the one lifecycle hook that fires whether the work
      //   finishes or the peer leaves mid-wait (an evicted Fiber is never
      //   resumed): either way the client is released, so no keep-alive
      //   connection outlives the deferral on the worker reactor
      $Token = Cancellation::fetch($Fiber);
      if ($Token === null || $Token->check()) {
         throw new LogicException('HTTP response resource must be used inside a deferred context — call it from defer().');
      }

      $this->Fiber = $Fiber;
      $Token->observe(function (Cancellation $Observed, bool $cancelled): void {
         $this->release();
      });
   }

   /**
    * Release the deferred context and every connection its client still holds.
    */
   private function release (): void
   {
      $this->Fiber = null;
      $this->Client->abort();
   }
}

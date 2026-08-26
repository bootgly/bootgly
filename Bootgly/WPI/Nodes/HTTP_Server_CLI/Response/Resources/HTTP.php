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
 * The wait is parked; the dial is not. Opening the connection (and the TLS
 * handshake) still runs a blocking select on the worker reactor, and every
 * deferral dials afresh — `connectTimeout` is therefore a hard stall budget
 * for the whole worker, and `0` freezes it until the peer answers.
 *
 * Register it once and call it from `defer()`:
 *
 * ```php
 * $HTTP_Server_CLI->configure(responseResources: [
 *    'Upstream' => static fn (object $Context): HTTP => new HTTP(host: 'api.example.com', secure: [])
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
    * The embedded client — knob surface only.
    *
    * Every knob not covered by the constructor (`retryOn`, `retryDelay`,
    * `maxResponseBytes`, ...) is set here. Never send through it: only
    * `request()`, `batch()` and `drain()` claim the deferred context, and
    * only that claim releases the client when the deferral settles.
    */
   public private(set) HTTP_Client_CLI $Client;

   // * Metadata
   // ! The deferred context that owns this resource for one generation. A
   //   definition-backed instance is rebuilt per defer(), but a plain capture
   //   (or a mount() without a definition) can carry the same object across
   //   contexts — and a parked drain answers only the Fiber that parked it.
   /** @var null|Fiber<mixed,mixed,mixed,mixed> */
   private null|Fiber $Fiber = null;
   // ! Whether a bridge was refused while a context owned this resource: the
   //   refused context would park on a wait that is not its own
   private bool $stale = false;


   /**
    * @param string $host Upstream host.
    * @param null|int $port Upstream port (null = 80, or 443 when `secure` is set).
    * @param array<string,mixed>|null $secure TLS stream context options (`[]` enables TLS with the defaults).
    * @param array<string,int>|null $pool Connection pool bounds inside one deferral: `['min' => N, 'max' => N]`.
    * @param int|float $timeout Response timeout in seconds (0 = no timeout).
    * @param int|float $connectTimeout Connection timeout in seconds. The dial is synchronous on the worker reactor, so this bounds how long the worker can stall on one unreachable upstream (0 = no timeout = an unbounded stall).
    * @param int $maxRedirects Maximum redirects to follow (0 = disabled).
    * @param int $maxRetries Maximum retries on connection/timeout failure (0 = disabled).
    * @param null|bool $enableHTTP2 HTTP/2 negotiation (null = ALPN when secure; true = also h2c; false = never).
    *
    * @throws RuntimeException When constructed outside the HTTP server reactor.
    */
   public function __construct (
      string $host,
      null|int $port = null,
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
         port: $port ?? ($secure === null ? 80 : 443),
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
    *
    * A carried instance is re-attached by every Response clone (`defer()`
    * forks the resources of the clone it works on), so a bridge is only
    * accepted while no deferred context owns this resource.
    */
   public function schedule (Closure $Wait): static
   {
      // ? The owner parks on the bridge it claimed under: swapping it from a
      //   clone's attach would hand its next episode a wait that never
      //   suspends (tripwire, scrap, a fabricated code 0). The refused context
      //   is told so at its first claim instead
      if ($this->Fiber !== null) {
         $this->stale = true;

         return $this;
      }

      $this->stale = false;
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
    *
    * @return Response The upstream response (the client's `Request\Response`).
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
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
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
    */
   public function batch (): static
   {
      $this->own();
      $this->Client->batch();

      return $this;
   }

   /**
    * Park the deferred Fiber until every batched request completes.
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
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
         throw new LogicException('HTTP response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().');
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

      // ? A context whose attach was refused while another owned this
      //   resource has no bridge of its own installed
      if ($this->stale) {
         throw new LogicException('HTTP response resource was attached to another response while owned — a carried instance cannot serve interleaved deferred contexts; register it as a responseResources factory instead.');
      }

      // ! The generation token proves the Fiber is a live deferred context —
      //   and it is the one lifecycle hook that fires whether the work
      //   finishes or the peer leaves mid-wait (an evicted Fiber is never
      //   resumed): either way the client is released, so no keep-alive
      //   connection outlives the deferral on the worker reactor
      // ? A handoff (SSE head, nested defer) settles the generation while its
      //   Fiber keeps running: settle() unpublishes the alias, so a settled
      //   context reads exactly like one that never was — the wait capability
      //   is gone either way
      $Token = Cancellation::fetch($Fiber);
      if ($Token === null || $Token->check()) {
         throw new LogicException('HTTP response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().');
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

      // ! Every generation dials afresh: abort() closes the pooled keep-alive
      //   connections too, so the next deferral pays one synchronous dial
      $this->Client->abort();

      // @ The generation has settled and its Fiber is never resumed — retire
      //   the episode notifier it may still be parked on instead of leaving
      //   two descriptors to the cycle collector
      $this->Client->unpark();
   }
}

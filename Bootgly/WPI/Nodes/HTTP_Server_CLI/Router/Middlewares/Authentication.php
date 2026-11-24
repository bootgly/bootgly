<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const PHP_INT_MAX;
use function count;
use function hrtime;
use function in_array;
use function is_finite;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function strncasecmp;
use function substr;
use Closure;
use Fiber;
use InvalidArgumentException;
use Throwable;
use WeakMap;
use WeakReference;

use const Bootgly\WPI;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


/**
 * Authentication middleware executor.
 *
 * The middleware delegates all mechanism-specific work to an `Authenticating`
 * guard strategy. It succeeds when any configured guard authenticates the
 * request and fails closed with either a custom fallback or the first guard's
 * challenge response.
 *
 * By default the guards run synchronously, on the worker. With `deferred`
 * the middleware is a deferring mode for guards that wait on I/O (a token
 * stored in a database, a remote introspection):
 *
 * - Every guard is first asked whether the request may carry its
 *   credentials (`Guard::detect()`, no I/O). When none does, the request is
 *   denied at once — the same answer as the synchronous mode, no deferral,
 *   no body captured.
 * - Otherwise the request is deferred once (`Response::defer()`): the guards
 *   run on the deferral's Request snapshot, may suspend, and the identity
 *   they expose lands on that snapshot — the Request the handler and every
 *   later middleware receive.
 * - The guard chain (and the denial) is bounded by `timeout` seconds; past
 *   it the request is answered `503` (`Response\Timeout`), even when a guard
 *   caught the Timeout. The handler is not bounded by it: it keeps
 *   `Response::$deferredTimeout`.
 * - Inside the deferral the answer is the deferred Response: a Response
 *   returned by the handler, a Fallback or a challenge that is not it is
 *   refused — logged once and answered with the first guard's challenge.
 * - The cookies the guards emitted (a rotated remember token, a regenerated
 *   session id) reach the client on every answer of that request, an error
 *   answer included (`Sealing`).
 * - Reached inside a deferral another Authentication in this mode opened
 *   (stacked on the global stack and the route), it runs there without
 *   deferring again; inside any other deferral it defers once more.
 */
class Authentication implements Middleware, Sealing
{
   // * Config
   /**
    * Whether the guards run inside a deferral (the deferring mode) instead
    * of synchronously on the worker.
    */
   public private(set) bool $deferred;
   /**
    * Seconds the guard chain of the deferring mode may take before the
    * request is answered `503`; `0` leaves it unbounded (the deferral's own
    * budget, `Response::$deferredTimeout`, still applies).
    */
   public private(set) int|float $timeout;

   // * Data
   /**
    * Ordered strategy of authentication guards for the current route.
    */
   public private(set) Authenticating $Authenticating;
   /**
    * Optional custom unauthorized response callback.
      *
      * The middleware normalizes the response status to `401 Unauthorized`
      * before and after the callback, so fallback formatters can customize the
      * body/headers without accidentally returning a successful status.
      * Redirecting fallbacks are the one exception: a callback result that is
      * already a redirect (3xx + `Location`) is returned untouched, so
      * browser/session flows can send guests to a login page.
    *
      * @var null|Closure(object,object):object
    */
   public private(set) null|Closure $Fallback;

   // * Metadata
   /**
    * Requests (their Exchange, and the snapshot) whose deferral an
    * Authentication in the deferring mode opened.
    *
    * @var null|WeakMap<object,true>
    */
   private static null|WeakMap $Owned = null;
   /**
    * `Set-Cookie` values the guard chain of a deferred request emitted, by
    * its Exchange and by its snapshot.
    *
    * @var null|WeakMap<object,array<int,string>>
    */
   private static null|WeakMap $Emitted = null;
   /** Lazy: only a refused Response is ever logged. */
   private static null|Logger $Logger = null;
   /** Whether this middleware already logged a refused Response. */
   private bool $refused = false;


   /**
      * Create an authentication middleware.
      *
      * At least one guard is required. Empty strategies fail during middleware
      * creation so protected routes cannot be misconfigured silently.
      *
      * @param null|Closure(object,object):object $Fallback
      * @param bool $deferred Run the guards inside a deferral (the deferring mode).
      * @param int|float $timeout Seconds the guard chain of the deferring
      *    mode may take before a `503`; `0` = unbounded.
      *
      * @throws InvalidArgumentException When no guard is configured, or the
      *    timeout is negative or not finite.
    */
   public function __construct (
      Authenticating $Authenticating,
      null|Closure $Fallback = null,
      bool $deferred = false,
      int|float $timeout = 5
   )
   {
      if (count($Authenticating->Guards) === 0) {
         throw new InvalidArgumentException('Authentication middleware requires at least one guard.');
      }
      if (is_finite($timeout) === false || $timeout < 0) {
         throw new InvalidArgumentException('Authentication timeout must be a finite number of seconds, 0 or more.');
      }

      // * Config
      $this->deferred = $deferred;
      $this->timeout = $timeout;

      // * Data
      $this->Authenticating = $Authenticating;
      $this->Fallback = $Fallback;
   }

   /**
    * Run the middleware pipeline with authentication enforcement.
    *
      * @param Closure(object,object):object $next
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // ?: Synchronous mode (the default)
      if ($this->deferred === false) {
         // @ Try guards in configured order.
         foreach ($this->Authenticating->Guards as $Guard) {
            if ($Guard->authenticate($Request)) {
               return $next($Request, $Response);
            }
         }

         // : Unauthorized.
         return $this->deny($Request, $Response);
      }

      // ! Already inside a running deferral: the Request is its snapshot
      //   (only a snapshot is ever linked to a generation)
      $Generation = Cancellation::fetch($Request);
      $inside = $Generation !== null && $Generation->check() === false;

      // ? No guard detects credentials: the invitation is answered now —
      //   no deferral, no body captured
      $detected = false;
      foreach ($this->Authenticating->Guards as $Guard) {
         if ($Guard->detect($Request)) {
            $detected = true;
            break;
         }
      }
      if ($detected === false) {
         $Result = $this->deny($Request, $Response);

         // :? Synchronously a Fallback's Response is adopted as ever; inside
         //    a deferral only the deferred Response answers
         return $inside ? $this->refuse($Response, $Result) : $Result;
      }

      // ?: Inside a deferral an Authentication in this mode opened (stacked)
      if ($inside && $this->check($Request)) {
         $this->settle($Response, $Request, $next);

         return $Response;
      }

      // : Defer once — the guards and the handler run on the snapshot
      return $Response->defer(function (Response $Response, Request $Request) use ($next): void {
         $this->settle($Response, $Request, $next);
      });
   }

   /**
    * Seal a deferred answer: queue the `Set-Cookie` values this request's
    * guard chain emitted that the chosen Response does not carry yet — an
    * error boundary's or the Catcher's fresh Response drops them otherwise,
    * and a rotated remember token the client never received would read as
    * theft on its next request. Idempotent; nothing for a request the
    * deferring mode did not authenticate.
    */
   public function seal (Request $Request, Response $Response): void
   {
      // ?
      $Emitted = self::$Emitted;
      if ($Emitted === null) {
         return;
      }

      // @@
      $Header = $Response->Header;
      foreach ($this->index($Request) as $Key) {
         foreach ($Emitted[$Key] ?? [] as $cookie) {
            if (in_array("Set-Cookie: {$cookie}", $Header->queued, true) === false) {
               $Header->queue('Set-Cookie', $cookie);
            }
         }
      }
   }

   /**
    * Run the guard chain of the deferring mode on a deferral's snapshot,
    * then the handler (`$next`) or the denial, keeping the deferred Response
    * as the answer.
    *
    * @param Closure(object,object):object $next
    */
   private function settle (Response $Response, Request $Request, Closure $next): void
   {
      // ! Mark this request's deferral as opened by this mode
      $Keys = $this->index($Request);
      $Owned = self::$Owned ??= new WeakMap;
      foreach ($Keys as $Key) {
         $Owned[$Key] = true;
      }

      // ! Guard bound — `$expired` is set by the deadline before it delivers
      $expired = false;
      $Disarm = $this->arm($Request, $expired);
      $Header = $Response->Header;
      $queued = $Header->queued;

      $authenticated = false;
      $Result = $Response;
      try {
         try {
            // @@ Try guards in configured order.
            foreach ($this->Authenticating->Guards as $Guard) {
               // ? Past the bound — a guard caught its Timeout
               if ($expired) {
                  break;
               }

               if ($Guard->authenticate($Request)) {
                  $authenticated = true;
                  break;
               }
            }

            // @ Unauthorized.
            if ($authenticated === false && $expired === false) {
               $Result = $this->deny($Request, $Response);
            }
         }
         finally {
            if ($Disarm !== null) {
               $Disarm();
            }

            // @ Keep what the chain emitted for every answer of this request
            $this->record($Keys, $queued, $Header->queued);
         }
      }
      catch (Throwable $Throwable) {
         // ? Past the bound, whatever the chain made of the Timeout is the bound
         if ($expired && $Throwable instanceof Timeout === false) {
            throw new Timeout($this->timeout);
         }

         throw $Throwable;
      }

      // ? Past the bound — a guard caught the Timeout and returned
      if ($expired) {
         throw new Timeout($this->timeout);
      }

      // @ Authorized: the handler, unbounded by the guard chain's timeout.
      if ($authenticated) {
         $Result = $next($Request, $Response);
      }

      // @ Only the deferred Response answers.
      $this->refuse($Response, $Result);
   }

   /**
    * Arm the guard chain's deadline on the reactor: past `timeout`, a
    * `Response\Timeout` is delivered at the chain's suspension point, and
    * again at each later one (re-armed for as long as the chain stays
    * parked). Returns the closure that disarms it, or null when nothing can
    * bound the chain (`timeout` 0, no reactor, no Fiber, no generation).
    *
    * @param bool $expired Set to true before the Timeout is delivered.
    */
   private function arm (Request $Request, bool &$expired): null|Closure
   {
      // ?
      if (
         $this->timeout <= 0
         || isSet(TCP_Server_CLI::$Event) === false
         || Fiber::getCurrent() === null
      ) {
         return null;
      }
      $Generation = Cancellation::fetch($Request);
      if ($Generation === null) {
         return null;
      }

      // ! Weak: neither the parked Fiber nor its generation is kept alive by
      //   a timer — and no local holds the running Fiber across a suspension
      $Event = TCP_Server_CLI::$Event;
      $Reference = WeakReference::create(Fiber::getCurrent());
      $Token = WeakReference::create($Generation);
      $timeout = $this->timeout;
      $Timer = new class {
         // * Data
         public int $ID = 0;
      };
      $arm = static function () use (&$arm, &$expired, $Event, $Reference, $Token, $Timer, $timeout): void {
         // ! Integer-domain clamp (as Response::bound()): a budget beyond the
         //   clock's headroom would wrap to a deadline in the past
         $now = (int) hrtime(true);
         $deadline = $timeout >= (PHP_INT_MAX - $now) / 1_000_000_000
            ? PHP_INT_MAX
            : $now + (int) ($timeout * 1_000_000_000);

         $Timer->ID = $Event->defer($deadline, static function () use (&$arm, &$expired, $Event, $Reference, $Token, $timeout): void {
            // ? Settled meanwhile — completion, handoff or teardown
            $Generation = $Token->get();
            if ($Generation === null || $Generation->check()) {
               return;
            }
            $Parked = $Reference->get();
            if ($Parked === null) {
               return;
            }

            $expired = true;
            // ! Re-arm BEFORE delivering: interrupt() resumes the Fiber
            //   synchronously, and the chain may park again
            $arm();
            $Event->interrupt($Parked, new Timeout($timeout));
         });
      };
      $arm();

      // :
      return static function () use ($Event, $Timer): void {
         $Event->cancel($Timer->ID);
      };
   }

   /**
    * Whether this request's deferral was opened by an Authentication in the
    * deferring mode.
    */
   private function check (Request $Request): bool
   {
      // ?
      $Owned = self::$Owned;
      if ($Owned === null) {
         return false;
      }

      // @@
      foreach ($this->index($Request) as $Key) {
         if (isSet($Owned[$Key])) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * The keys a deferred request is known by: its Exchange — shared by every
    * snapshot of the request, a nested deferral's included — and the
    * snapshot itself.
    *
    * @return array<int,object>
    */
   private function index (Request $Request): array
   {
      // !
      $Exchange = Exchange::fetch($Request);

      // :
      return $Exchange === null ? [$Request] : [$Exchange, $Request];
   }

   /**
    * Record the `Set-Cookie` values queued after `$before` was taken.
    *
    * @param array<int,object> $Keys
    * @param array<int,string> $before
    * @param array<int,string> $after
    */
   private function record (array $Keys, array $before, array $after): void
   {
      // !
      $cookies = [];
      foreach ($after as $index => $line) {
         if (isSet($before[$index]) || strncasecmp($line, 'Set-Cookie: ', 12) !== 0) {
            continue;
         }

         $cookies[] = substr($line, 12);
      }
      // ?
      if ($cookies === []) {
         return;
      }

      // @@
      $Emitted = self::$Emitted ??= new WeakMap;
      foreach ($Keys as $Key) {
         $Emitted[$Key] = [...($Emitted[$Key] ?? []), ...$cookies];
      }
   }

   /**
    * Keep the deferred Response as the answer: a Response returned inside
    * the deferral (by the handler, a Fallback or a challenge) that is not it
    * is refused — logged once per middleware, the deferred Response started
    * over (the cookies already emitted kept) and answered with the first
    * guard's challenge.
    */
   private function refuse (Response $Response, object $Result): Response
   {
      // ?
      if ($Result === $Response) {
         return $Response;
      }

      // @ A wiring error: logged once, with no request data
      if ($this->refused === false) {
         $this->refused = true;

         self::$Logger ??= new Logger(channel: self::class, global: true);
         self::$Logger->log(
            warning: 'A Response other than the deferred one was returned inside the deferring Authentication: it was refused and the challenge answered instead.@.;'
         );
      }

      // ! The Router adopted it as the ambient Response — give the segment
      //   its own back, so later cookies land on the answer
      $WPI = WPI;
      if ($WPI->Response === $Result) {
         $WPI->Response = $Response;
      }

      // @ Start the answer over, keeping the cookies already emitted
      $Header = $Response->Header;
      $cookies = [];
      foreach ($Header->queued as $line) {
         if (strncasecmp($line, 'Set-Cookie: ', 12) === 0) {
            $cookies[] = substr($line, 12);
         }
      }
      $Header->clean();
      foreach ($cookies as $cookie) {
         $Header->queue('Set-Cookie', $cookie);
      }
      $Response->stream = false;
      $Response->chunked = false;
      $Response(401);

      // @ The first guard's challenge; a challenge that answers elsewhere
      //   falls back to the generic one
      $Guard = $this->Authenticating->Guards[0];
      if ($Guard->challenge($Response) !== $Response) {
         Challenge::reject($Response);
      }

      // :
      return $Response;
   }

   /**
    * Resolve the unauthorized response for a failed authentication attempt.
    */
   private function deny (object $Request, object $Response): object
   {
      // ?: Custom fallback.
      if ($this->Fallback !== null) {
         Challenge::mark($Response);

         $Result = ($this->Fallback)($Request, $Response);
         // ?: Redirecting fallbacks (e.g. guest → login page) keep their 3xx
         if ($this->detect($Result)) {
            return $Result;
         }

         return Challenge::mark($Result);
      }

      // @ Use the first configured guard challenge as the route challenge.
      $Guard = $this->Authenticating->Guards[0] ?? null;
      if ($Guard !== null) {
         return $Guard->challenge($Response);
      }

      // : Generic unauthorized fallback.
      return Challenge::reject($Response);
   }

   /**
    * Detect a redirecting fallback result (3xx status + `Location` header).
    */
   private function detect (object $Result): bool
   {
      // ! Status code.
      $code = $Result->code ?? null;
      // ?
      if (is_int($code) === false || $code < 300 || $code > 399) {
         return false;
      }

      // ! Location header.
      $Header = $Result->Header ?? null;
      // ?
      if (is_object($Header) === false || method_exists($Header, 'get') === false) {
         return false;
      }

      // :
      $location = $Header->get('Location');

      return is_string($location) && $location !== '';
   }
}
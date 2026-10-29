<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


use Closure;
use WeakReference;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * A built-in resource that answers for the Response it was built with (its
 * protected `$Response`) and can let that response go.
 *
 * A response owns its resources, so a resource holding it strongly closed a
 * cycle only the collector could break. `release()` — reached from `clean()`,
 * which `Resources::release()` calls on every instance a registry built once
 * its deferred job ends (`Resources::fetch()` on one built after that), and
 * which, on a scoped instance such as View, `Resources::reset()` calls between
 * requests and `Resources::detach()` when it is replaced or removed — moves
 * the response into a weak edge, and `resolve()` reads it from there for as
 * long as it lives: a retained response is answered exactly as before.
 *
 * Once that response is gone — the resource was kept without it — every call
 * answers for a detached stand-in Response instead of the finished clone:
 *
 * - a fresh one per call: nothing one late call writes to it (body, headers,
 *   status) reaches another call, so no caller gets back what another sent;
 * - deferred and bound to no request: neither encoder serializes it — a
 *   handler that returns it leaves its request unanswered, as one that
 *   returned the finished clone did — neither the router nor an encoder
 *   adopts it as the worker's Response, which keeps its configured resources
 *   and presets, and a recovery boundary that returns it declines;
 * - with no transport, it hints, defers and streams nothing; no late call
 *   throws, and nothing it is given ever reaches the wire;
 * - built by the one builder every stand-in comes from (the Response's own,
 *   reached bound to its scope), it refers to nothing that leads back to it:
 *   refcounting frees it the moment its caller lets go — its Header with it
 *   only while no late call has built that Header's Cookies, which refer
 *   back to the Header once a read builds them: that pair is then left to
 *   the cycle collector.
 *
 * Only the exact built-in class releases: a subclass may read `$Response`
 * itself, so it keeps the strong edge it always had.
 */
trait Releasable
{
   // * Metadata
   // ! The response once release() let it go — `$Response` is unset then
   /** @var null|WeakReference<Response> */
   private null|WeakReference $Reference = null;
   // ! The Response's private stand-in builder, bound to its scope on the
   //   first stand-in this class answers for (see resolve())
   /** @var null|Closure():Response */
   private static null|Closure $Substitute = null;


   /**
    * Let the response go: hold it weakly from here on.
    */
   private function release (): void
   {
      // ? A subclass reads `$Response` itself, or already released
      if (static::class !== self::class || isSet($this->Response) === false) {
         return;
      }

      $Response = $this->Response;
      // ! Exact class (checked above): no subclass can hook the property
      unset($this->Response); // @phpstan-ignore unset.possiblyHookedProperty

      // ? A detached stand-in — what a registry kept past its response builds
      //   on (see Resources::resolve()) — is never held, not even weakly:
      //   every later call answers for a fresh one
      if ($Response->deferred && $Response->Request === null) {
         return;
      }

      $this->Reference = WeakReference::create($Response);
   }

   /**
    * Resolve the response this resource answers for after release().
    */
   private function resolve (): Response
   {
      // ?: The response while it lives — read, never held again
      $Response = $this->Reference?->get();
      if ($Response !== null) {
         return $Response;
      }

      // ! Gone: a fresh detached stand-in for this call alone (see above),
      //   from the Response's own builder — bound once, never re-declared
      self::$Substitute ??= Closure::bind(
         static function (): Response {
            return Response::substitute();
         },
         null,
         Response::class,
      );

      // :
      return (self::$Substitute)();
   }
}

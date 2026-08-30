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


use function explode;
use function hash;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use Closure;

use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


class ETag implements Middleware, Sealing
{
   // * Config
   public private(set) bool $weak;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param bool $weak Whether to generate weak ETags (default: true).
    */
   public function __construct (
      bool $weak = true
   )
   {
      // * Config
      $this->weak = $weak;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Pass through to handler first
      $Response = $next($Request, $Response);

      // @ One pass serves both cycles — a deferred generation runs it at
      //   settlement (`seal()`), against the Response chosen for the wire
      $this->tag($Request, $Response);

      // :
      return $Response;
   }

   public function seal (Request $Request, Response $Response): void
   {
      $this->tag($Request, $Response);
   }

   /**
    * Validate and stamp one representation — the shared half of both cycles,
    * typed loosely because the synchronous unit tests hand doubles.
    *
    * @param Request $Request
    * @param Response $Response
    */
   private function tag (object $Request, object $Response): void
   {
      // ? Only validate cacheable responses (audit F-11): 2xx success / 3xx
      //   redirect. Error and auth-challenge bodies (4xx/5xx) must never be
      //   ETagged or 304-revalidated — caching them risks serving a stale
      //   error or challenge as if it were the resource.
      $code = $Response->code;
      if ($code < 200 || $code >= 400) {
         return;
      }

      // ? Only compute ETag for non-empty bodies
      $body = $Response->Body->raw;
      if (strlen($body) === 0) {
         return;
      }

      // @ Generate the ETag over the body as it will be delivered. Order this
      //   middleware OUTSIDE `Compression` so `$Response->Body->raw` is already
      //   the encoded (compressed) representation when this runs — otherwise
      //   the validator would not identify the bytes actually on the wire
      //   (audit F-11). The sealing walk preserves the order: innermost seals
      //   first, exactly as the synchronous unwind runs the post-`$next()`.
      $hash = hash('xxh3', $body);
      $etag = $this->weak ? 'W/"' . $hash . '"' : '"' . $hash . '"';
      $Response->Header->set('ETag', $etag);

      // ? Conditional request — RFC 7232 §3.2 `If-None-Match`.
      $ifNoneMatch = $Request->Header->get('If-None-Match');
      if ($ifNoneMatch !== null && $this->compare($ifNoneMatch, $etag)) {
         $Response(code: 304, body: ''); // ! `__invoke` mutates in place
      }
   }

   /**
    * RFC 7232 §3.2 `If-None-Match` evaluation: `*` matches any current
    *   representation; otherwise the field is a comma-separated list of
    *   entity-tags compared against the response tag with the weak comparison
    *   function (§2.3.2) — the `W/` weakness prefix is ignored for the match.
    */
   private function compare (string $ifNoneMatch, string $etag): bool
   {
      $candidate = trim($ifNoneMatch);

      // ? `*` matches any current representation.
      if ($candidate === '*') {
         return true;
      }

      $current = $this->strip($etag);

      // @ Weak comparison against each listed entity-tag.
      foreach (explode(',', $candidate) as $tag) {
         if ($this->strip(trim($tag)) === $current) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Strip the optional `W/` weakness prefix, yielding the opaque entity-tag
    *   used by the weak comparison function.
    */
   private function strip (string $tag): string
   {
      if (str_starts_with($tag, 'W/')) {
         return substr($tag, 2);
      }

      return $tag;
   }
}

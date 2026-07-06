<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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


class ETag implements Middleware
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

      // ? Only validate cacheable responses (audit F-11): 2xx success / 3xx
      //   redirect. Error and auth-challenge bodies (4xx/5xx) must never be
      //   ETagged or 304-revalidated — caching them risks serving a stale
      //   error or challenge as if it were the resource.
      $code = (int) $Response->code; // @phpstan-ignore-line
      if ($code < 200 || $code >= 400) {
         return $Response;
      }

      // ? Only compute ETag for non-empty bodies
      $body = $Response->Body->raw; // @phpstan-ignore-line
      if (strlen($body) === 0) {
         return $Response;
      }

      // @ Generate the ETag over the body as it will be delivered. Order this
      //   middleware OUTSIDE `Compression` so `$Response->Body->raw` is already
      //   the encoded (compressed) representation when this runs — otherwise
      //   the validator would not identify the bytes actually on the wire
      //   (audit F-11).
      $hash = hash('xxh3', $body);
      $etag = $this->weak ? 'W/"' . $hash . '"' : '"' . $hash . '"';
      $Response->Header->set('ETag', $etag); // @phpstan-ignore-line

      // ? Conditional request — RFC 7232 §3.2 `If-None-Match`.
      $ifNoneMatch = $Request->Header->get('If-None-Match'); // @phpstan-ignore-line
      if ($ifNoneMatch !== null && $this->compare($ifNoneMatch, $etag)) {
         return $Response(code: 304, body: ''); // @phpstan-ignore-line
      }

      // :
      return $Response;
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

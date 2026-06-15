<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function max;
use function time;
use Closure;

use Bootgly\ABI\Resources\Cache;
use Bootgly\API\Workables\Server\Middleware;


/**
 * Fixed-window rate limiter middleware.
 *
 * Counters live in a shared Cache backend (shared-memory by default, or Redis),
 * so the limit is enforced across every worker process — fixing the historical
 * per-worker static-array behavior that multiplied the limit by worker count.
 * Each client's window opens on its first request (counter creation sets the
 * TTL) and rolls over when that entry expires.
 */
class RateLimit implements Middleware
{
   // * Config
   public private(set) int $limit;
   public private(set) int $window;
   public private(set) bool $trustForwarded;
   public private(set) null|Closure $clock;

   // * Data
   protected Cache $Cache;


   public function __construct (
      int $limit = 60,
      int $window = 60,
      bool $trustForwarded = false,
      null|Closure $clock = null,
      null|Cache $Cache = null
   )
   {
      // * Config
      $this->limit = $limit;
      $this->window = $window;
      // ? Key selection (audit F-3). Default keys on the immutable TCP transport
      //   peer (`$Request->peer`), which a client cannot spoof — so an attacker
      //   co-located with / behind a trusted proxy cannot evade the limit by
      //   rotating X-Forwarded-For. Set true ONLY behind a genuinely trusted
      //   proxy chain, to key on the proxy-derived `$Request->address` instead.
      $this->trustForwarded = $trustForwarded;
      $this->clock = $clock;

      // * Data
      $this->Cache = $Cache ?? new Cache([
         'driver' => 'shared',
         'prefix' => 'ratelimit:',
         'clock' => $clock,
      ]);
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // !
      $now = $this->clock === null
         ? time()
         : (int) ($this->clock)();
      // @ Spoof-proof key by default: the immutable transport peer. Opt into the
      //   proxy-derived application IP only when the forwarded chain is trusted.
      $ip = $this->trustForwarded
         ? $Request->address // @phpstan-ignore-line
         : $Request->peer;   // @phpstan-ignore-line

      // @ Consume a request token from the shared counter
      $count = $this->Cache->increment($ip, 1, $this->window);
      $remaining = $this->limit - $count;

      // @ Window end = counter creation + window (derived from the entry TTL)
      $TTL = $this->Cache->remain($ip);
      $reset = $TTL > 0
         ? $now + $TTL
         : $now + $this->window;

      // @ Set rate limit headers
      $Response->Header->set('X-RateLimit-Limit', (string) $this->limit); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Remaining', (string) max(0, $remaining)); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Reset', (string) $reset); // @phpstan-ignore-line

      // ? Rate limit exceeded
      if ($remaining < 0) {
         $Response->Header->set('Retry-After', (string) max(1, $reset - $now)); // @phpstan-ignore-line

         return $Response(code: 429, body: 'Too Many Requests'); // @phpstan-ignore-line
      }

      // :
      return $next($Request, $Response);
   }
}

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


use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function time;
use Closure;

use Bootgly\API\Workables\Server\Middleware;


/**
 * In-memory rate limiter middleware.
 *
 * ⚠️  MULTI-WORKER LIMITATION: Counters are stored in a static PHP array scoped
 * to each worker process. In a multi-worker deployment, each worker maintains
 * independent counters, effectively multiplying the configured limit by the number
 * of workers. For accurate rate limiting in multi-worker setups, replace this
 * middleware with a shared-backend implementation (Redis, Memcached, etc.).
 */
class RateLimit implements Middleware
{
   // * Config
   public private(set) int $limit;
   public private(set) int $window;

   // * Data
   /**
    * In-memory storage: IP => [count, window_start]
    *
    * @var array<string, array{int, int}>
    */
   private static array $counters = [];

   // * Metadata
   private static int $lastPurge = 0;


   public function __construct (
      int $limit = 60,
      int $window = 60
   )
   {
      // * Config
      $this->limit = $limit;
      $this->window = $window;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // !
      $now = time();
      $ip = $Request->address; // @phpstan-ignore-line

      // @ Purge expired entries periodically
      if (($now - self::$lastPurge) >= $this->window) {
         $this->purge($now);
      }

      // @ Consume a request token
      $remaining = $this->consume($ip, $now);

      // @ Set rate limit headers
      $Response->Header->set('X-RateLimit-Limit', (string) $this->limit); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Remaining', (string) \max(0, $remaining)); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Reset', (string) ($this->windowStart($ip) + $this->window)); // @phpstan-ignore-line

      // ? Rate limit exceeded
      if ($remaining < 0) {
         $Response->Header->set('Retry-After', (string) $this->window); // @phpstan-ignore-line

         return $Response(code: 429, body: 'Too Many Requests'); // @phpstan-ignore-line
      }

      // :
      return $next($Request, $Response);
   }

   private function consume (string $key, int $now): int
   {
      if (isset(self::$counters[$key]) === false || ($now - self::$counters[$key][1]) >= $this->window) {
         // @ New window
         self::$counters[$key] = [1, $now];

         return $this->limit - 1;
      }

      // @ Increment counter
      self::$counters[$key][0]++;

      return $this->limit - self::$counters[$key][0];
   }

   private function windowStart (string $key): int
   {
      return self::$counters[$key][1] ?? 0;
   }

   private function purge (int $now): void
   {
      foreach (self::$counters as $key => $entry) {
         if (($now - $entry[1]) >= $this->window) {
            unset(self::$counters[$key]);
         }
      }

      self::$lastPurge = $now;
   }

   /**
    * Persist counters to file (call on server shutdown).
    */
   public static function persist (string $path): void
   {
      file_put_contents($path, json_encode(self::$counters));
   }

   /**
    * Restore counters from file (call on worker boot).
    */
   public static function restore (string $path): void
   {
      if (is_file($path)) {
         $data = file_get_contents($path);
         if ($data !== false) {
            $counters = json_decode($data, true);
            if (is_array($counters)) {
               /** @var array<string, array{int, int}> $counters */
               self::$counters = $counters;
            }
         }
      }
   }
}

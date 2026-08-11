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


use function constant;
use function chr;
use function debug_backtrace;
use function defined;
use function floor;
use function hash;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function str_pad;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;
use Closure;
use InvalidArgumentException;

use Bootgly\ABI\Resources\Cache;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;


/**
 * Rate limiter middleware.
 *
 * Counters live in a shared Cache backend (shared-memory by default, or Redis),
 * so the limit is enforced across every worker process — fixing the historical
 * per-worker static-array behavior that multiplied the limit by worker count.
 *
 * Hardening (audit F-4):
 *  - IPv6 keys are aggregated to a configurable prefix (default `/64`) so an
 *    attacker with a routed `/64` cannot mint 2⁶⁴ distinct keys.
 *  - A weighted sliding window (default) smooths the fixed-window boundary burst.
 *  - An optional cross-worker global ceiling caps aggregate request volume.
 *  - The counter key is pluggable (IP by default, or any identity/API key) via a
 *    `key` resolver closure.
 *  - Every logical policy has a stable namespace. Distinct constructor source
 *    lines are isolated by default; `scope` deliberately shares principal counters,
 *    while `globalScope` can share only the application-wide ceiling.
 *    Factories, subclasses, or multiple expressions on one source line that
 *    create independent policies must pass explicit scopes. A shared global
 *    scope is intended for route-disjoint policies, so one request is counted once.
 */
class RateLimit implements Middleware
{
   // * Config
   public private(set) int $limit;
   public private(set) int $window;
   public private(set) bool $trustForwarded;
   public private(set) int $ipv6Prefix;
   public private(set) int $globalLimit;
   public private(set) Algorithms $algorithm;
   public private(set) null|Closure $key;
   public private(set) null|Closure $clock;
   /** Stable identity for this policy's per-principal counters. */
   public private(set) string $scope;
   /** Stable identity for its optional aggregate counter. */
   public private(set) string $globalScope;

   // * Data
   protected Cache $Cache;
   /** Versioned, domain-separated prefix for per-principal counters. */
   private string $principalNamespace;
   /** Versioned, domain-separated prefix for the aggregate counter. */
   private string $globalNamespace;

   // * Metadata
   /** Shared cache key for the optional global aggregate counter. */
   private const string GLOBAL_KEY = '__global__';


   public function __construct (
      int $limit = 60,
      int $window = 60,
      bool $trustForwarded = false,
      int $ipv6Prefix = 64,
      int $globalLimit = 0,
      Algorithms $algorithm = Algorithms::Sliding,
      null|Closure $key = null,
      null|Closure $clock = null,
      null|Cache $Cache = null,
      null|string $scope = null,
      null|string $globalScope = null
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
      // ? IPv6 aggregation prefix (audit F-4): mask IPv6 keys to this network
      //   length so a routed /64 maps to ONE bucket. IPv4 keys are unaffected.
      $this->ipv6Prefix = $ipv6Prefix;
      // ? Optional cross-worker global ceiling (audit F-4): 0 disables it.
      $this->globalLimit = $globalLimit;
      // ? Counting algorithm (audit F-4): sliding window by default.
      $this->algorithm = $algorithm;
      // ? Pluggable key resolver (audit F-4): `fn (Request): null|string`.
      //   Return a custom key (identity, API key, …) or null to fall back to
      //   the IP key. Lets callers rate-limit on something other than the IP.
      $this->key = $key;
      $this->clock = $clock;
      // ? A route set is materialized independently inside every worker. A
      //   random/object identity would therefore split one logical quota into
      //   per-worker buckets. The construction site is deterministic across
      //   those worker-local builds; factories or rolling deployments that
      //   need an identity independent of source location pass `scope`.
      $this->scope = $scope === null
         ? self::locate()
         : self::validate($scope, 'scope');
      $this->globalScope = $globalScope === null
         ? $this->scope
         : self::validate($globalScope, 'globalScope');

      // @ Version and domain separation prevent a custom principal named
      //   `__global__` from reaching the aggregate counter. Window/algorithm
      //   semantics prevent explicitly shared but incompatible policies from
      //   inheriting one another's TTL or counter representation.
      $algorithmKey = $this->algorithm === Algorithms::Sliding
         ? 'sliding'
         : 'fixed';
      $this->principalNamespace = 'v2:p:'
         . hash('sha256', $this->scope)
         . ":{$algorithmKey}:{$this->window}:";
      $this->globalNamespace = 'v2:g:'
         . hash('sha256', $this->globalScope)
         . ":{$this->window}:";

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

      // @ Resolve the counter key (audit F-4): pluggable resolver first, then
      //   the default IP key with IPv6 aggregation.
      $key = null;
      if ($this->key !== null) {
         $resolved = ($this->key)($Request);
         if (is_string($resolved) && $resolved !== '') {
            $key = $resolved;
         }
      }
      if ($key === null) {
         // @ Spoof-proof key by default: the immutable transport peer. Opt into
         //   the proxy-derived application IP only when the chain is trusted.
         $ip = $this->trustForwarded
            ? $Request->address // @phpstan-ignore-line
            : $Request->peer;   // @phpstan-ignore-line
         $key = self::mask((string) $ip, $this->ipv6Prefix);
      }
      $key = $this->principalNamespace . $key;

      // @ Count this request under the configured algorithm.
      if ($this->algorithm === Algorithms::Sliding) {
         [$count, $reset] = $this->slide($key, $now);
      }
      else {
         $count = $this->Cache->increment($key, 1, $this->window);
         $TTL = $this->Cache->remain($key);
         $reset = $TTL > 0
            ? $now + $TTL
            : $now + $this->window;
      }

      $remaining = $this->limit - $count;

      // @ Set rate limit headers
      $Response->Header->set('X-RateLimit-Limit', (string) $this->limit); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Remaining', (string) max(0, (int) floor($remaining))); // @phpstan-ignore-line
      $Response->Header->set('X-RateLimit-Reset', (string) $reset); // @phpstan-ignore-line

      // ? Per-key limit exceeded
      if ($remaining < 0) {
         $Response->Header->set('Retry-After', (string) max(1, $reset - $now)); // @phpstan-ignore-line

         return $Response(code: 429, body: 'Too Many Requests'); // @phpstan-ignore-line
      }

      // ? Optional global ceiling (audit F-4): an aggregate cross-worker cap a
      //   distributed/botnet client cannot dodge by spreading across keys. Only
      //   counted for requests that passed the per-key check.
      if ($this->globalLimit > 0) {
         $globalKey = $this->globalNamespace . self::GLOBAL_KEY;
         $globalCount = $this->Cache->increment($globalKey, 1, $this->window);
         if ($globalCount > $this->globalLimit) {
            $globalTTL = $this->Cache->remain($globalKey);
            $retry = $globalTTL > 0 ? $globalTTL : $this->window;
            $Response->Header->set('Retry-After', (string) max(1, $retry)); // @phpstan-ignore-line

            return $Response(code: 429, body: 'Too Many Requests'); // @phpstan-ignore-line
         }
      }

      // :
      return $next($Request, $Response);
   }

   /**
    * Weighted sliding-window count for `$key` at time `$now` (audit F-4).
    *
    * Blends the current fixed window with the fraction of the previous window
    * still inside the sliding view, so a burst straddling a boundary no longer
    * grants a fresh `limit`. Two counters per key, both kept for two windows.
    *
    * @return array{float|int, int} `[estimated count, reset timestamp]`.
    */
   protected function slide (string $key, int $now): array
   {
      $window = $this->window;
      $index = intdiv($now, $window);          // current window index
      $elapsed = $now - ($index * $window);     // seconds into the current window
      $weight = ($window - $elapsed) / $window; // previous-window fraction still in view

      $currentKey = "{$key}:{$index}";
      $previousKey = "{$key}:" . ($index - 1);

      // @ Increment the current bucket; keep both buckets alive across two
      //   windows so the previous count is still readable here.
      $current = $this->Cache->increment($currentKey, 1, $window * 2);
      $previousRaw = $this->Cache->fetch($previousKey);
      $previous = is_numeric($previousRaw) ? (int) $previousRaw : 0;

      $estimated = $current + ($previous * $weight);
      // Once the next boundary passes, the previous-window contribution decays.
      $reset = ($index + 1) * $window;

      return [$estimated, $reset];
   }

   /**
    * Derive the same default policy identity when a lazy route declaration is
    * materialized independently by multiple workers.
    *
    * Two intentional policies produced by one factory or physical source line
    * must pass an explicit `scope`; source movement resets the automatic namespace once.
    */
   private static function locate (): string
   {
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
      $frame = null;
      foreach ($trace as $index => $candidate) {
         if (
            $index > 0
            && is_string($candidate['file'] ?? null)
            && is_int($candidate['line'] ?? null)
         ) {
            $frame = $candidate;

            break;
         }
      }
      if ($frame === null) {
         throw new InvalidArgumentException(
            'RateLimit requires an explicit scope when its construction site cannot be identified.'
         );
      }

      $file = $frame['file'];
      $line = $frame['line'];
      foreach (['BOOTGLY_WORKING_DIR', 'BOOTGLY_ROOT_DIR'] as $rootName) {
         if (defined($rootName)) {
            $root = (string) constant($rootName);
            if (str_starts_with($file, $root)) {
               $file = $rootName . ':' . substr($file, strlen($root));

               break;
            }
         }
      }

      return 'auto:' . hash('sha256', "{$file}:{$line}");
   }

   /** Validate an explicit policy namespace before hashing it into cache keys. */
   private static function validate (string $scope, string $parameter): string
   {
      $length = strlen($scope);
      if ($length === 0 || $length > 256 || trim($scope) === '') {
         throw new InvalidArgumentException(
            "RateLimit {$parameter} must contain between 1 and 256 non-whitespace bytes."
         );
      }

      return $scope;
   }

   /**
    * Aggregate an IPv6 address to its `/$prefix` network (audit F-4); pass any
    * non-IPv6 value (IPv4, a non-IP custom key) through unchanged.
    */
   protected static function mask (string $ip, int $prefix): string
   {
      $packed = @inet_pton($ip);
      // Not an IPv6 address (IPv4 packs to 4 bytes; invalid → false).
      if ($packed === false || strlen($packed) !== 16) {
         return $ip;
      }

      if ($prefix < 0) {
         $prefix = 0;
      }
      else if ($prefix > 128) {
         $prefix = 128;
      }

      // @ Zero every bit beyond the network portion.
      $fullBytes = intdiv($prefix, 8);
      $remainder = $prefix % 8;

      $network = substr($packed, 0, $fullBytes);
      if ($remainder !== 0 && $fullBytes < 16) {
         $maskByte = chr((0xFF << (8 - $remainder)) & 0xFF);
         $network .= ($packed[$fullBytes] & $maskByte);
      }
      $network = str_pad($network, 16, "\x00");

      $normalized = inet_ntop($network);

      return ($normalized === false ? $ip : $normalized) . "/{$prefix}";
   }
}

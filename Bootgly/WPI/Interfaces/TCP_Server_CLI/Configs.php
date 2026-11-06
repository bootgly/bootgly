<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function is_nan;
use function is_string;
use ArgumentCountError;
use InvalidArgumentException;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Code\__String\Bytes;
use Bootgly\ABI\Configs as Configuring;


/**
 * Transport configuration of a TCP Server.
 *
 * Handed to `TCP_Server_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('0.0.0.0', 8080, 4)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs implements Configuring
{
   // * Config
   /** Domain name or IP address to bind. */
   public private(set) string $host;
   /** Port number to bind. */
   public private(set) int $port;
   /** Number of worker processes to fork. */
   public private(set) int $workers;
   /** @var null|array<string,mixed> Secure SSL/TLS Stream Context options. */
   public private(set) null|array $secure;
   /** User to drop privileges to after socket binding. */
   public private(set) null|string $user;
   /** Group to drop privileges to after socket binding. */
   public private(set) null|string $group;
   // # Recycling
   /**
    * Dispatches after which a worker recycles: its replacement is forked at
    * once while it stops accepting and drains its in-flight work like a
    * reload. What one dispatch is
    * belongs to the node: an accepted connection (TCP, WebSocket) or a request
    * (HTTP, keep-alive requests included). A worker whose slot's previous
    * worker still drains serves on past it — still accepting — until that
    * one is gone (one draining worker per slot). `0` never recycles.
    */
   public private(set) int $maxRequests;
   /**
    * Spread of each worker's own limit around `maxRequests`, as a fraction
    * (`0.1` = ±10%), so workers do not recycle together. From `0` to `1`.
    */
   public private(set) float $jitter;
   /**
    * Bytes of real memory (`memory_get_usage(true)`) past which a worker
    * recycles, checked between dispatches. It must lie between the memory a
    * worker starts with and `memory_limit`: a start refuses one that does
    * not, a reload turns it off. A worker whose slot's previous worker still
    * drains may stay past it until that one is gone: one drain at most
    * (`drainTimeout` + 5 s). `0` keeps the ceiling off.
    */
   public private(set) int $maxMemory;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options.
    * @param int $maxRequests Dispatches after which a worker recycles (`0` = never).
    * @param float $jitter Spread of each worker's limit, a fraction from `0` to `1`.
    * @param int|string $maxMemory Memory ceiling of a worker in bytes, or a size
    *        such as `"512M"` (read by `Bytes::parse()`); `0` = none.
    *
    * @throws ArgumentCountError When `host`, `port` or `workers` is missing.
    * @throws InvalidArgumentException When `maxRequests` or `maxMemory` is
    *         negative, `maxMemory` is not a size, or `jitter` is outside `0`–`1`.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      null|int $workers = null,
      null|array $secure = null,
      null|string $user = null,
      null|string $group = null,
      int $maxRequests = 0,
      float $jitter = 0.1,
      int|string $maxMemory = 0
   )
   {
      // ? Required — the guard slot forces every parameter to be optional, so
      //   the mandatory ones are enforced here instead of by the engine.
      if ($host === null || $port === null || $workers === null) {
         $Configs = static::class;

         throw new ArgumentCountError(
            "{$Configs} requires the named arguments: host, port, workers."
         );
      }
      // ? Recycling — a limit that can never be reached is refused, not ignored
      if ($maxRequests < 0) {
         throw new InvalidArgumentException(
            "maxRequests must be 0 (never recycle) or a positive count, {$maxRequests} given."
         );
      }
      if (is_nan($jitter) || $jitter < 0.0 || $jitter > 1.0) {
         // ! NAN is never interpolated: PHP 8.5 warns on its string coercion
         $given = is_nan($jitter) ? 'NAN' : (string) $jitter;

         throw new InvalidArgumentException(
            "jitter must be a fraction from 0 to 1, {$given} given."
         );
      }
      if (is_string($maxMemory)) {
         // @ A size like "512M"; a malformed one throws InvalidArgumentException
         $maxMemory = Bytes::parse($maxMemory);
      }
      if ($maxMemory < 0) {
         throw new InvalidArgumentException(
            "maxMemory must be 0 (no ceiling) or a positive count of bytes, {$maxMemory} given."
         );
      }

      // * Config
      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->secure = $secure;

      $this->user = $user;
      $this->group = $group;
      // # Recycling
      $this->maxRequests = $maxRequests;
      $this->jitter = $jitter;
      $this->maxMemory = $maxMemory;
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use const STREAM_PEEK;
use function count;
use function is_resource;
use function max;
use function microtime;
use function min;
use function mt_rand;
use function stream_select;
use function stream_socket_recvfrom;

use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


/**
 * Client connection pool.
 *
 * Parks established connections between requests and hands them back
 * idle-first, co-locating extra acquisitions on multiplexing-capable
 * connections (HTTP/2) before reporting exhaustion to the caller.
 */
class Pool
{
   public const int DEFAULT_MIN = 0;
   public const int DEFAULT_MAX = 1;
   public const int DEFAULT_FAILURES = 2;
   public const float DEFAULT_RETRY = 5.0;
   public const float DEFAULT_JITTER = 0.25;

   // * Config
   public int $min;
   public int $max;
   /** Idle eviction age in seconds (0 = never evict). */
   public int|float $expiration = 0;

   // * Data
   /** @var array<int,Connection> socketId => Connection */
   public protected(set) array $idle = [];
   /** @var array<int,Connection> socketId => Connection */
   public protected(set) array $busy = [];

   // * Metadata
   /** @var array<int,int> socketId => max concurrent streams (1 = HTTP/1.x) */
   protected array $capacities = [];
   /** @var array<int,int> socketId => in-flight streams */
   protected array $streams = [];
   /** @var array<int,float> socketId => parked-at timestamp */
   protected array $parked = [];
   /** Live pooled connections (attached - dropped). */
   public private(set) int $created = 0;
   public private(set) int $failures = 0;
   public private(set) float $retry = 0.0;
   /** Round-robin cursor over busy-with-spare-capacity connections. */
   private int $cursor = 0;
   /** Whether the pool is out of retry quarantine. */
   public bool $healthy {
      get => $this->retry <= 0.0 || microtime(true) >= $this->retry;
   }


   /**
    * Construct the pool with its size bounds.
    *
    * @param array<string,int> $pool ['min' => N, 'max' => N]
    */
   public function __construct (array $pool = [])
   {
      // * Config
      $this->min = max(0, $pool['min'] ?? self::DEFAULT_MIN);
      // ? The Select event backend caps at 1000 sockets
      $this->max = min(1000, max(1, $pool['max'] ?? self::DEFAULT_MAX));
      // ? Keep the floor within the ceiling
      if ($this->min > $this->max) {
         $this->min = $this->max;
      }
   }

   /**
    * Register a freshly-established Connection into the pool.
    *
    * @param Connection $Connection The established connection to pool.
    * @param int $capacity Max concurrent streams (1 = HTTP/1.x).
    * @param bool $busy Whether the connection already carries one in-flight stream.
    *
    * @return self
    */
   public function attach (Connection $Connection, int $capacity = 1, bool $busy = false): self
   {
      // !
      $id = $Connection->id;

      // @ Track stream bookkeeping
      $this->capacities[$id] = max(1, $capacity);
      $this->streams[$id] = $busy ? 1 : 0;

      // @ Place the connection
      if ($busy) {
         $this->busy[$id] = $Connection;
      }
      else {
         $this->idle[$id] = $Connection;
         $this->parked[$id] = microtime(true);
      }

      $this->created++;

      // :
      return $this;
   }

   /**
    * Acquire a pooled connection — idle-first, then busy-with-spare capacity.
    *
    * @return null|Connection A usable connection, or null when the pool is
    * exhausted (the caller dials when `created < max`, or queues).
    */
   public function acquire (): null|Connection
   {
      // ! Age out expired idle connections first
      $this->evict();

      // @@ Idle-first: verify each parked connection before reuse
      foreach ($this->idle as $id => $Connection) {
         // ? Died while parked — discard and try the next one
         if ($this->check($Connection) === false) {
            // ! close() fires re-entrant client hooks (drop + promote) that
            //   may mutate the pool while this loop iterates its snapshot
            $Connection->close();
            $this->drop($Connection);

            continue;
         }

         // ? Taken by a re-entrant hook (close → disconnect → promote)
         //   while this snapshot was being walked — never hand the same
         //   connection to two requests
         if (isSet($this->idle[$id]) === false) { // @phpstan-ignore isset.offset (re-entrant close() hooks mutate $idle mid-iteration)
            continue;
         }

         // @ Promote idle → busy
         unset($this->idle[$id], $this->parked[$id]);
         $this->busy[$id] = $Connection;
         $this->streams[$id]++;

         return $Connection;
      }

      // @@ Busy-with-spare: co-locate on a multiplexing connection (h2)
      $eligible = [];
      foreach ($this->busy as $id => $Connection) {
         if ($this->streams[$id] < $this->capacities[$id]) {
            $eligible[] = $id;
         }
      }

      if ($eligible !== []) {
         // @ Round-robin over the eligible connections
         $id = $eligible[$this->cursor % count($eligible)];
         $this->cursor++;

         $this->streams[$id]++;

         return $this->busy[$id];
      }

      // : Exhausted — the caller dials when `created < max`, or queues
      return null;
   }

   /**
    * Release one in-flight stream back to the pool.
    *
    * @param Connection $Connection The connection whose stream finished.
    *
    * @return self
    */
   public function release (Connection $Connection): self
   {
      // !
      $id = $Connection->id;

      // ? Untracked connection — releasing is idempotent
      if (isSet($this->idle[$id]) === false && isSet($this->busy[$id]) === false) {
         return $this;
      }

      // ? Dead connection — discard instead of parking
      if (
         $Connection->status === Connection::STATUS_CLOSED
         || is_resource($Connection->Socket) === false
      ) {
         return $this->drop($Connection);
      }

      // @ Consume one in-flight stream
      $streams = max(0, ($this->streams[$id] ?? 1) - 1);
      $this->streams[$id] = $streams;

      // ?: Multiplexed siblings still in flight — stay busy
      if ($streams > 0) {
         return $this;
      }

      // @ Park the drained connection back into the idle pool
      unset($this->busy[$id]);
      $this->idle[$id] = $Connection;
      $this->parked[$id] = microtime(true);

      // : A clean release is proof of health
      return $this->recover();
   }

   /**
    * Drop one pooled connection from bookkeeping (the caller closes the socket).
    *
    * @param Connection $Connection The connection to forget.
    *
    * @return self
    */
   public function drop (Connection $Connection): self
   {
      // !
      $id = $Connection->id;

      // ? Already dropped — bookkeeping is idempotent
      if (isSet($this->idle[$id]) === false && isSet($this->busy[$id]) === false) {
         return $this;
      }

      // @ Remove the connection from all pool maps
      unset(
         $this->idle[$id],
         $this->busy[$id],
         $this->capacities[$id],
         $this->streams[$id],
         $this->parked[$id]
      );

      if ($this->created > 0) {
         $this->created--;
      }

      // :
      return $this;
   }

   /**
    * Check whether a parked connection is still usable (non-consuming probe).
    *
    * @param Connection $Connection The connection to probe.
    *
    * @return bool
    */
   public function check (Connection $Connection): bool
   {
      // !
      $Socket = $Connection->Socket;

      // ? The stream is already gone
      if (is_resource($Socket) === false) {
         return false;
      }
      // ? Locally closed or never established
      if ($Connection->status !== Connection::STATUS_ESTABLISHED) {
         return false;
      }

      // @ Probe readability without blocking
      $read = [$Socket];
      $write = [];
      $except = null;
      $selected = @stream_select($read, $write, $except, 0, 0);

      // ? Probe failure — the connection cannot be trusted
      if ($selected === false) {
         return false;
      }
      // ?: Nothing to read — a parked connection is expected to be silent
      if ($selected === 0) {
         return true;
      }

      // @ Readable while parked — PEEK never consumes, so TLS framing stays intact
      $peeked = @stream_socket_recvfrom($Socket, 1, STREAM_PEEK);

      // ? EOF/RST — the peer ended the stream
      if ($peeked === false || $peeked === '') {
         return false;
      }

      // ?: Multiplexed connections (h2) legitimately park with control
      //    frames pending (SETTINGS/PING/WINDOW_UPDATE) — still usable
      if (($this->capacities[$Connection->id] ?? 1) > 1) {
         return true;
      }

      // : Any byte pending on a parked HTTP/1.x connection is unsolicited
      //   data — unusable
      return false;
   }

   /**
    * Evict idle connections parked beyond the expiration window.
    *
    * @return int The number of connections evicted.
    */
   public function evict (): int
   {
      // ? Eviction disabled
      if ($this->expiration <= 0) {
         return 0;
      }

      // !
      $now = microtime(true);
      $evicted = 0;

      // @@ Age out expired idle connections
      foreach ($this->idle as $id => $Connection) {
         if ($now - ($this->parked[$id] ?? $now) < $this->expiration) {
            continue;
         }

         $Connection->close();
         $this->drop($Connection);

         $evicted++;
      }

      // :
      return $evicted;
   }

   /**
    * Cap the max concurrent streams of a tracked connection (h2 SETTINGS hook).
    *
    * @param Connection $Connection The tracked connection to resize.
    * @param int $capacity The new max concurrent streams (min 1).
    *
    * @return self
    */
   public function cap (Connection $Connection, int $capacity): self
   {
      // !
      $id = $Connection->id;

      // ? Untracked connection — nothing to resize
      if (isSet($this->capacities[$id]) === false) {
         return $this;
      }

      $this->capacities[$id] = max(1, $capacity);

      // :
      return $this;
   }

   /**
    * Quarantine this pool after repeated failures.
    *
    * @param float $seconds Base quarantine duration.
    * @param int $failures Failure threshold that triggers the quarantine.
    * @param float $jitter Jitter factor spread over the base duration.
    *
    * @return self
    */
   public function penalize (float $seconds = self::DEFAULT_RETRY, int $failures = self::DEFAULT_FAILURES, float $jitter = self::DEFAULT_JITTER): self
   {
      $this->failures++;

      // ? Below the failure threshold — no quarantine yet
      if ($this->failures < $failures) {
         return $this;
      }

      // @ Quarantine with jittered backoff to avoid synchronized retries
      $spread = $seconds * $jitter * (mt_rand(0, 1000) / 1000);
      $this->retry = microtime(true) + $seconds + $spread;

      // :
      return $this;
   }

   /**
    * Clear the pool health penalty after a successful operation.
    *
    * @return self
    */
   public function recover (): self
   {
      $this->failures = 0;
      $this->retry = 0.0;

      return $this;
   }
}

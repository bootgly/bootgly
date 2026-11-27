<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use function array_key_first;
use function ceil;
use function count;
use function is_string;
use function max;
use function strlen;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;


/**
 * Per-worker DNS answer cache, bounded by entries and by bytes.
 *
 * Keys are `{type}:{lowercased absolute name}`. An entry lives for the TTL it
 * was stored with; once expired it is still kept for the stale window, and is
 * served only on request (`find(stale: true)`), after a fresh lookup failed —
 * a subset of RFC 8767. A failure stored over a positive entry keeps that
 * entry as its stale payload.
 *
 * Eviction runs on every store over a bound: entries past their stale window
 * first, then the least recently used (a hit moves an entry to the end).
 */
final class Cache
{
   // * Config
   /** Most entries held at once. */
   public private(set) int $capacity;
   /** Most bytes (an estimate of the answers' size) held at once. */
   public private(set) int $budget;
   /** Most bytes one answer may take to be held: a larger one is returned, never cached. */
   public private(set) int $limit;
   /** Seconds an expired positive entry may still be served after a failed lookup. */
   public private(set) int $stale;

   // * Data
   // ...

   // * Metadata
   /** Entries held now. */
   public int $entries {
      get => count($this->Entries);
   }
   /** Estimated bytes held now. */
   public private(set) int $bytes = 0;
   /**
    * @var array<string,array{Answer: Answer, expires: float, size: int, Stale: null|array{Answer: Answer, expires: float}}>
    */
   private array $Entries = [];


   /**
    * @param int $capacity Most entries held at once (`>= 1`).
    * @param int $budget Most estimated bytes held at once (`>= 1`).
    * @param int $stale Seconds an expired positive entry stays servable after a failure (`>= 0`).
    * @param int $limit Most estimated bytes one answer may take to be held (`>= 1`).
    *
    * @throws InvalidArgumentException On a bound out of range.
    */
   public function __construct (int $capacity = 1024, int $budget = 4_194_304, int $stale = 300, int $limit = 8192)
   {
      // ?
      if ($capacity < 1 || $budget < 1 || $stale < 0 || $limit < 1) {
         throw new InvalidArgumentException('Cache bounds must be positive (stale window non-negative).');
      }

      // * Config
      $this->capacity = $capacity;
      $this->budget = $budget;
      $this->stale = $stale;
      $this->limit = $limit;
   }

   /**
    * Find a cached answer.
    *
    * @param string $key The entry key.
    * @param float $now The current time, on the caller's clock (the node's is monotonic: `Query::clock()`).
    * @param bool $stale Whether an expired positive answer inside the stale window may be returned
    *    (only after a fresh lookup failed).
    *
    * @return null|Answer The answer with its remaining TTL; `null` on a miss.
    */
   public function find (string $key, float $now, bool $stale = false): null|Answer
   {
      $Entry = $this->Entries[$key] ?? null;
      // ? Miss
      if ($Entry === null) {
         return null;
      }

      // @ Touch: a hit moves the entry to the LRU end
      unset($this->Entries[$key]);
      $this->Entries[$key] = $Entry;

      // ?: Fresh
      if ($stale === false) {
         if ($Entry['expires'] <= $now) {
            return null;
         }

         return $this->age($Entry['Answer'], $Entry['expires'], $now);
      }

      // ?: Stale — an expired positive answer, or the one a failure kept
      $Answer = $Entry['Answer'];
      $expires = $Entry['expires'];
      if ($Answer->Outcome !== Outcomes::Answered) {
         $Stale = $Entry['Stale'];
         if ($Stale === null) {
            return null;
         }

         $Answer = $Stale['Answer'];
         $expires = $Stale['expires'];
      }
      if ($expires + $this->stale <= $now) {
         return null;
      }

      return new Answer($Answer->Outcome, $Answer->name, $Answer->Records, 0);
   }

   /**
    * Store an answer for its TTL.
    *
    * A failure stored over a positive entry keeps it as the stale payload.
    *
    * @param string $key The entry key.
    * @param Answer $Answer The answer; its `TTL` is the lifetime.
    * @param float $now The current time, on the caller's clock (the node's is monotonic: `Query::clock()`).
    *
    * @return bool Whether it was stored (`false` when it alone exceeds the per-answer limit or the
    *    byte budget).
    */
   public function store (string $key, Answer $Answer, float $now): bool
   {
      $size = $this->measure($key, $Answer);
      // ? An answer over the per-answer limit, or the whole budget, is never held
      if ($size > $this->limit || $size > $this->budget) {
         return false;
      }

      // ! A failure keeps the positive answer it replaces (serve-stale)
      $Stale = null;
      $Previous = $this->Entries[$key] ?? null;
      if ($Previous !== null) {
         if ($Answer->Outcome === Outcomes::Failed) {
            $Stale = $Previous['Answer']->Outcome === Outcomes::Answered
               ? ['Answer' => $Previous['Answer'], 'expires' => $Previous['expires']]
               : $Previous['Stale'];
         }

         $this->bytes -= $Previous['size'];
         unset($this->Entries[$key]);
      }

      // @ Store at the LRU end
      $this->Entries[$key] = [
         'Answer' => $Answer,
         'expires' => $now + $Answer->TTL,
         'size' => $size,
         'Stale' => $Stale,
      ];
      $this->bytes += $size;

      // @ Bound
      $this->evict($now);

      return isSet($this->Entries[$key]);
   }

   /**
    * Drop every entry.
    */
   public function flush (): void
   {
      $this->Entries = [];
      $this->bytes = 0;
   }

   /**
    * Evict entries until both bounds hold: past their stale window first, then LRU.
    */
   private function evict (float $now): void
   {
      // ? Within bounds
      if (count($this->Entries) <= $this->capacity && $this->bytes <= $this->budget) {
         return;
      }

      // @@ Entries past their stale window
      foreach ($this->Entries as $key => $Entry) {
         $expires = max($Entry['expires'], $Entry['Stale']['expires'] ?? 0.0);
         if ($expires + $this->stale <= $now) {
            $this->bytes -= $Entry['size'];
            unset($this->Entries[$key]);
         }
      }

      // @@ Least recently used
      while (count($this->Entries) > $this->capacity || $this->bytes > $this->budget) {
         $key = array_key_first($this->Entries);
         if ($key === null) {
            break;
         }

         $this->bytes -= $this->Entries[$key]['size'];
         unset($this->Entries[$key]);
      }
   }

   /**
    * Rebuild an answer with its remaining TTL.
    */
   private function age (Answer $Answer, float $expires, float $now): Answer
   {
      $TTL = (int) max(0.0, ceil($expires - $now));

      return new Answer($Answer->Outcome, $Answer->name, $Answer->Records, $TTL);
   }

   /**
    * Estimate the bytes an entry holds: the key, the names and every RDATA string, plus a fixed
    * overhead per entry and per record.
    */
   private function measure (string $key, Answer $Answer): int
   {
      $size = 128 + strlen($key) + strlen($Answer->name);
      foreach ($Answer->Records as $Record) {
         $size += 96 + strlen($Record->name);
         $data = $Record->data;
         if (is_string($data)) {
            $size += strlen($data);
            continue;
         }

         foreach ($data as $value) {
            $size += is_string($value) ? strlen($value) + 16 : 16;
         }
      }

      return $size;
   }
}

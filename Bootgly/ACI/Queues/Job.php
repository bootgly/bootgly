<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use function bin2hex;
use function random_bytes;


/**
 * A queued message.
 *
 * Unlike a scheduled `Schedule\Job` (which may hold a live Closure), a queued
 * Job crosses process boundaries — so it carries only serializable state: a
 * handler class-string, a payload array, and bookkeeping (attempts, availability).
 * The handler object is built by the Worker at run time from `$Handler`.
 */
class Job
{
   // * Config
   /**
    * The handler to run.
    *
    * @var class-string<Handler>
    */
   public private(set) string $Handler;
   /**
    * Arbitrary serializable payload passed to the handler via the job.
    *
    * @var array<string,mixed>
    */
   public private(set) array $payload;

   // * Data
   /**
    * Number of failed attempts so far.
    */
   public protected(set) int $attempts = 0;
   /**
    * Unix timestamp from which the job is due (0 = immediately, stamped on push).
    */
   public protected(set) int $available = 0;

   // * Metadata
   /**
    * Unique job identity.
    */
   public private(set) string $id;


   /**
    * @param class-string<Handler> $Handler
    * @param array<string,mixed> $payload
    */
   public function __construct (string $Handler, array $payload = [])
   {
      // * Config
      $this->Handler = $Handler;
      $this->payload = $payload;

      // * Metadata
      $this->id = bin2hex(random_bytes(8));
   }

   /**
    * Record one more failed attempt (used by the driver on release).
    */
   public function attempt (): static
   {
      $this->attempts++;

      // :
      return $this;
   }

   /**
    * Set the timestamp from which the job becomes due.
    *
    * @param int $timestamp Unix timestamp from which the job is due.
    */
   public function postpone (int $timestamp): static
   {
      $this->available = $timestamp;

      // :
      return $this;
   }
}

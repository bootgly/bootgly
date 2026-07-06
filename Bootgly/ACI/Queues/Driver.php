<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use Bootgly\ACI\Queues\Config;


/**
 * Queue driver contract.
 *
 * Concrete drivers (File, Redis) implement one blocking backend each, scoped by
 * queue name. Delivery is at-least-once: reserve() atomically claims a job with
 * a visibility timeout; complete() acks it; release() requeues it (retry); bury()
 * dead-letters it; recover() returns claims left stale past the visibility timeout.
 */
abstract class Driver
{
   // * Config
   public Config $Config;


   /**
    * Bind the configuration shared by every driver operation.
    *
    * @param Config $Config Shared queue configuration.
    */
   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Enqueue a job onto a queue.
    *
    * @param string $queue Target queue name.
    * @param Job $Job Job to enqueue.
    */
   abstract public function enqueue (string $queue, Job $Job): bool;
   /**
    * Atomically claim the next due job (visibility timeout applies); null when idle.
    *
    * @param string $queue Queue to claim from.
    */
   abstract public function reserve (string $queue): null|Job;
   /**
    * Acknowledge a finished job (remove it).
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to acknowledge.
    */
   abstract public function complete (string $queue, Job $Job): bool;
   /**
    * Requeue a job, becoming due again after $delay seconds (retry/backoff).
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to requeue.
    * @param int $delay Seconds until the job becomes due again.
    */
   abstract public function release (string $queue, Job $Job, int $delay = 0): bool;
   /**
    * Move a job to the dead-letter store (terminal failure).
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to dead-letter.
    */
   abstract public function bury (string $queue, Job $Job): bool;
   /**
    * Release claims left reserved past the visibility timeout; returns the count recovered.
    *
    * @param string $queue Queue to recover stale claims for.
    */
   abstract public function recover (string $queue): int;
   /**
    * Number of jobs ready (not counting reserved/failed).
    *
    * @param string $queue Queue to count.
    */
   abstract public function count (string $queue): int;
   /**
    * Remove every job (ready, reserved and failed) for a queue.
    *
    * @param string $queue Queue to clear.
    */
   abstract public function clear (string $queue): bool;
}

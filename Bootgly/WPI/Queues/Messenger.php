<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Queues;


use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;


/**
 * HTTP-facing queue dispatch adapter over the `ACI/Queues` contract.
 *
 * Request handlers enqueue work without touching the queue internals: dispatch()
 * builds a Job from a handler class-string + payload and enqueues it; push()
 * enqueues a prepared Job. It only ever **enqueues** (a quick local write or one
 * Redis round-trip) — the blocking consume loop runs in the `queue run` worker
 * process, never on the HTTP event loop.
 */
class Messenger
{
   // * Data
   public Queues $Queues;


   /**
    * Wrap an ACI queue manager, building one from config when needed.
    *
    * @param array<string,mixed>|Config|Queues $config Queue config array, a prepared Config, or an existing manager.
    */
   public function __construct (array|Config|Queues $config = [])
   {
      // * Data
      $this->Queues = $config instanceof Queues
         ? $config
         : new Queues($config);
   }

   /**
    * Build a job from a handler + payload and enqueue it onto a queue.
    *
    * @param class-string<Handler> $Handler Handler that will process the job.
    * @param array<string,mixed> $payload Serializable payload for the handler.
    * @param string $queue Target queue name.
    */
   public function dispatch (string $Handler, array $payload = [], string $queue = 'default'): Job
   {
      $Job = new Job($Handler, $payload);

      $this->Queues->fetch($queue)->enqueue($Job);

      // :
      return $Job;
   }

   /**
    * Enqueue a prepared job onto a queue.
    *
    * @param Job $Job Job to enqueue.
    * @param string $queue Target queue name.
    */
   public function push (Job $Job, string $queue = 'default'): bool
   {
      // :
      return $this->Queues->fetch($queue)->enqueue($Job);
   }
}

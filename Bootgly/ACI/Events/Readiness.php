<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


use function is_resource;
use InvalidArgumentException;

use Bootgly\ACI\Events\Scheduler;


/**
 * Event-loop readiness request for a stream resource.
 *
 * Deferred work can suspend with this object when it needs explicit read or
 * write readiness instead of the default resource-as-read behavior.
 */
class Readiness
{
   // * Config
   /** @var resource */
   public private(set) mixed $socket;
   public private(set) int $flag;
   public private(set) float $deadline;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a readiness request for a stream resource.
    *
    * @param resource $socket
    */
   public function __construct (mixed $socket, int $flag = Scheduler::SCHEDULE_READ, float $deadline = 0.0)
   {
      // ?
      if (is_resource($socket) === false) {
         throw new InvalidArgumentException('Readiness socket must be a resource.');
      }

      if ($flag !== Scheduler::SCHEDULE_READ && $flag !== Scheduler::SCHEDULE_WRITE) {
         throw new InvalidArgumentException('Readiness flag must be read or write.');
      }

      // * Config
      $this->socket = $socket;
      $this->flag = $flag;
      $this->deadline = $deadline;
   }

   /**
    * Create a read-readiness request.
    *
    * @param resource $socket
    */
   public static function read (mixed $socket, float $deadline = 0.0): self
   {
      return new self($socket, Scheduler::SCHEDULE_READ, $deadline);
   }

   /**
    * Create a write-readiness request.
    *
    * @param resource $socket
    */
   public static function write (mixed $socket, float $deadline = 0.0): self
   {
      return new self($socket, Scheduler::SCHEDULE_WRITE, $deadline);
   }
}

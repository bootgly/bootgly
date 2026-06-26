<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Channels;


use function count;

use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * A room: a named set of sessions that receive the same broadcast frame.
 */
class Channel
{
   // * Data
   public readonly string $name;
   /** @var array<int, Session> Members keyed by connection id. */
   public array $Sessions = [];


   public function __construct (string $name)
   {
      $this->name = $name;
   }

   public function join (Session $Session): void
   {
      $this->Sessions[$Session->id] = $Session;
   }

   public function leave (Session $Session): void
   {
      unset($this->Sessions[$Session->id]);
   }

   /**
    * Fan a pre-encoded frame out to every member (optionally except one).
    * The frame is built once by the caller and written to each member.
    *
    * @return int The number of members the frame was written to.
    */
   public function broadcast (string $frame, null|Session $Except = null): int
   {
      $exceptId = $Except !== null
         ? $Except->id
         : -1;

      $sent = 0;
      foreach ($this->Sessions as $Session) {
         // ?
         if ($Session->id === $exceptId) {
            continue;
         }

         if ($Session->deliver($frame)) {
            $sent++;
         }
      }

      // :
      return $sent;
   }

   public function count (): int
   {
      return count($this->Sessions);
   }
}

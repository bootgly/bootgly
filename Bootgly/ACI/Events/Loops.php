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


interface Loops
{
   /**
    * Add a socket to the event loop.
    * 
    * @param resource $Socket
    * @param int $flag
    * @param mixed $payload
    *
    * @return bool
    */
   public function add ($Socket, int $flag, $payload): bool;
   /**
    * Remove a socket from the event loop.
    * 
    * @param resource $Socket
    * @param int $flag
    *
    * @return bool
    */
   public function del ($Socket, int $flag): bool;

   /**
    * Start the event loop.
    *
    * @return void
    */
   public function loop (): void;

   /**
    * Stop the event loop.
    *
    * @return void
    */
   public function destroy (): void;
}

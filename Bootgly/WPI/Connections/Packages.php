<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Connections;


interface Packages
{
   /**
    * Read data from the socket in loop
    *
    * @param resource $Socket
    * @param null|int $length
    * @param null|int $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool;
   /**
    * Write data to the socket in loop
    *
    * @param resource $Socket
    * @param null|int $length
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null): bool;

   /**
    * Read data from the socket
    *
    * @param resource $Socket 
    *
    * @return void 
    */
   public function read (&$Socket): void;
   /**
    * Write data to the socket
    *
    * @param resource $Socket 
    *
    * @return void 
    */
   public function write (&$Socket, null|int $length = null): void;
}

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
   public function write (&$Socket): void;
}

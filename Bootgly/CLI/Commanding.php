<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


interface Commanding
{
   /**
    * Run the command with the given arguments and options.
    * 
    * @param array<string> $arguments 
    * @param array<string> $options 
    * @return bool 
    */
   public function run (array $arguments = [], array $options = []): bool;
}

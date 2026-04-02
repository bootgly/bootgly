<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Commands;


use Closure;
use Bootgly\CLI\Command;


interface Middleware
{
   /**
    * Process the command through the middleware.
    *
    * @param Command $Command The command being executed.
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string,bool|int|string> $options The options passed to the command.
    * @param Closure $next The next middleware or command handler.
    *
    * @return bool
    */
   public function process (Command $Command, array $arguments, array $options, Closure $next): bool;
}

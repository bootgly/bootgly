<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Closure;

use Bootgly\ABI\Data\__String\Path;

use Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\Scripts;
use Bootgly\CLI\Terminal\components\Alert\Alert;


class ServeCommand extends Command
{
   // * Config
   public int $group = 2;

   // * Data
   // @ Command
   public string $name = 'serve';
   public string $description = 'Serve the project on the Bootgly HTTP Server CLI';


   public function run (array $arguments, array $options) : bool
   {
      Scripts::execute('http-server-cli');

      return true;
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Bootgly\CLI;
use Bootgly\CLI\Command;


class SetupCommand extends Command
{
   public string $name = 'setup';
   public string $description = 'Setup bootgly CLI on /usr/local/bin';


   public function run (array $arguments, array $options) : bool
   {
      // @ Set the name of the script to be installed
      $scriptName = 'bootgly';
      // @ Set the destination directory for global installation
      $installDir = '/usr/local/bin';

      // @ Check if the script is executed with superuser privileges
      if (posix_getuid() !== 0) {
         echo "This script needs to be executed with superuser privileges." . PHP_EOL;
         exit(1);
      }
      // @ Check if the destination directory exists
      if (is_dir($installDir) === false) {
         echo "The installation directory `$installDir` does not exist." . PHP_EOL;
      }

      // @ Create a symbolic link to the PHP script in the destination directory
      $scriptPath = BOOTGLY_DIR . $scriptName;
      if (symlink($scriptPath, "$installDir/$scriptName") === false) {
         echo "Failed to create a symbolic link to `$scriptName` in the installation directory." . PHP_EOL;
         exit(1);
      }
      // @ Add execution permissions to the script
      if (chmod("$installDir/$scriptName", 0755) === false) {
         echo "Failed to set execution permissions for the script `$scriptName`." . PHP_EOL;
         exit(1);
      }

      echo "The script was successfully installed in `$installDir/$scriptName`." . PHP_EOL;
      echo "You can now use bootgly CLI by simply typing '$scriptName' in any directory." . PHP_EOL;

      return true;
   }
}

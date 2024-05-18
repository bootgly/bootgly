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


use function Bootgly\ABI\copy_recursively;

use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Alert\Alert;


class BootCommand extends Command
{
   // * Config
   public int $group = 1;

   public string $name = 'boot';
   public string $description = 'Boot Bootgly resources, projects, etc.';


   public function run (array $arguments, array $options) : bool
   {
      $Output = CLI->Terminal->Output;

      if ($options['resources'] || $options === []) {
         $Output->render('@#green:Booting resource directories...@;');

         $Alert = new Alert($Output);

         if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
            $Alert->Type::Failure->set();
            $Alert->message = 'No resources to boot!';
            $Alert->render();
            return false;
         }

         // TODO get resources dirs dynamically
         $resource_dirs = [
            'projects/',
            'public/',
            'scripts/',
            'tests/',
            'workdata/',
         ];

         // ?
         foreach ($resource_dirs as $index => $dir) {
            if (is_dir(BOOTGLY_WORKING_DIR . $dir) === true) {
               unset($resource_dirs[$index]);
            }
         }

         // @
         foreach ($resource_dirs as $dir) {
            $resource_dir_copied = copy_recursively(BOOTGLY_ROOT_DIR . $dir, BOOTGLY_WORKING_DIR . $dir);
            if ($resource_dir_copied === false) {
               $Alert->Type::Failure->set();
               $Alert->message = 'Failed to copy resource dir: @#red:' . $dir . '@;';
               $Alert->render();
            }
            else {
               $Alert->Type::Success->set();
               $Alert->message = 'Resource dir copied: @#cyan:' . $dir . '@;';
               $Alert->render();
            }
         }

         $Output->render('@#green:OK@;@.;');
      }

      $Output->write(PHP_EOL);

      return true;
   }
}

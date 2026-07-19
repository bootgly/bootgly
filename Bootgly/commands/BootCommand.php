<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use const PHP_EOL;
use function copy;
use function is_dir;
use function mkdir;

use const Bootgly\CLI;
use function Bootgly\ABI\copy_recursively;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;


class BootCommand extends Command
{
   // * Config
   public int $group = 1;

   public string $name = 'boot';
   public string $description = 'Boot Bootgly resources, projects, etc.';


   public function run (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      if (($options['resources'] ?? false) || $options === []) {
         $Output->render('@.;@#green:Booting resource directories...@;@.;');

         $Alert = new Alert($Output);

         if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
            $Alert->Type::Failure->set();
            $Alert->message = 'No resources to boot!';
            $Alert->render();
            return false;
         }

         $Alert->spaced = false;

         // # projects/ — seeded with an EMPTY registry: the framework projects
         // (Demos, Benchmarks) are never listed in a kit; the wizard fills the
         // registry on create/import
         if (is_dir(BOOTGLY_WORKING_DIR . 'projects') === false) {
            mkdir(BOOTGLY_WORKING_DIR . 'projects', 0755, true);
            copy(
               BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/Bootgly.projects.php',
               BOOTGLY_WORKING_DIR . 'projects/Bootgly.projects.php'
            );

            $Alert->Type::Success->set();
            $Alert->message = 'Resource dir created: @#cyan:projects/@;';
            $Alert->render();
         }

         // # tests/ — seeded with the registry template + the example suite
         // (a running tour of the test API): the framework suites are never
         // listed in a kit; the user registers project suites
         if (is_dir(BOOTGLY_WORKING_DIR . 'tests') === false) {
            copy_recursively(
               BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/tests',
               BOOTGLY_WORKING_DIR . 'tests'
            );

            $Alert->Type::Success->set();
            $Alert->message = 'Resource dir created: @#cyan:tests/@;';
            $Alert->render();
         }

         // TODO get resources dirs dynamically
         $resource_dirs = [
            'public/',
            'scripts/',
            'storage/',
         ];

         // ?
         foreach ($resource_dirs as $index => $dir) {
            if (is_dir(BOOTGLY_WORKING_DIR . $dir) === true) {
               unset($resource_dirs[$index]);
            }
         }

         // @
         foreach ($resource_dirs as $dir) {
            copy_recursively(BOOTGLY_ROOT_DIR . $dir, BOOTGLY_WORKING_DIR . $dir);

            $Alert->Type::Success->set();
            $Alert->message = "Resource dir copied: @#cyan:{$dir}@;";
            $Alert->render();
         }

         $Output->render('@#green:OK@;@.;');
      }

      $Output->write(PHP_EOL);

      return true;
   }
}

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


use Bootgly;
use Bootgly\CLI;
use Bootgly\CLI\Command;


class ProjectCommand extends Command
{
   // * Config
   public int $group = 2;

   // * Data
   // @ Command
   public string $name = 'project';
   public string $description = 'Manage Bootgly projects.';
   public array $arguments = [
      'list' => [
         'description' => 'List Bootgly projects.',
         'options'     => []
      ]
   ];


   public function run (array $arguments, array $options) : bool
   {
      return match ($arguments[0]) {
         #'create' => $this->create($options),
         'list'   => $this->list($options),
         default  => $this->help($arguments)
      };
   }

   // @ Subcommands
   // TODO support to attributes too
   public function list (array $options) : bool
   {
      $Output = CLI::$Terminal->Output;

      $projects = @include(BOOTGLY_WORKABLES_DIR . 'projects/@.php');

      $projectsList = @$projects['list'];
      if ( ! empty($projectsList) ) {
         $Output->render('@.;@#cyan: Projects list: @; @.;');
      } else {
         $Output->render('@.;@#red: Projects is empty: @; @.;');
      }

      foreach($projectsList as $index => $project) {
         $index += 1;

         $Output->render(
            "@#magenta: {$index} @; - "
            . $project['paths'][0]
            . PHP_EOL . PHP_EOL
         );
      }

      return true;
   }

   // ...
   public function help (array $arguments) : bool
   {
      $Output = CLI::$Terminal->Output;

      if ( empty($arguments) ) {
         $Output->render('@#red: Available arguments: @; list. @.;');
         return false;
      } else if ( count($arguments) > 1 ) {
         $Output->render("@#red: Too many arguments! @; @.;");
      } else {
         $Output->render("@#red: Argument invalid: `{$arguments[0]}`. @; @.;");
      }

      return true;
   }
}

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
         'options'     => [
            '--bootgly'
         ]
      ]
   ];


   public function run (array $arguments, array $options) : bool
   {
      return match ($arguments[0]) {
         #'create'   => $this->create($options),

         #'configure' => $this->configure($options),
         'list'      => $this->list($options),

         default     => $this->help($arguments)
      };
   }

   // @ Subcommands
   // TODO support to attributes too
   public function list (array $options) : bool
   {
      $Output = CLI::$Terminal->Output;

      if (@$options[0] === 'bootgly') {
         ${'@'} = @include(BOOTGLY_DIR . 'projects/@.php');
      } else {
         ${'@'} = @include(BOOTGLY_WORKABLES_DIR . 'projects/@.php');  
      }

      $projects = @${'@'};
      if ( ! empty($projects['list']) ) {
         $Output->render('@.;@#cyan: Project list: @; @.;');
      } else {
         $Output->render('@.;@#red: Project list is empty: @; @.;');
      }

      foreach ($projects['list'] as $index => $project) {
         // * Data
         $default = '';
         if ($projects['default'] === $index) {
            $default = "@#green: [default] @;";
         }
         // * Meta
         $index += 1;

         $Output->render(
            "@#magenta: #{$index} @; - "
            . $project['paths'][0] . $default
            . PHP_EOL
         );
      }

      $Output->write(PHP_EOL);

      return true;
   }

   // ...
   public function help (array $arguments) : bool
   {
      $Output = CLI::$Terminal->Output;

      if ( empty($arguments) ) {
         $output = '@#red: Available arguments: @; @.;';
         foreach ($this->arguments as $name => $value) {
            $output .= $name;
         }
         $output .= '@.;';

         $Output->render($output);
      } else if ( count($arguments) > 1 ) {
         $Output->render("@#red: Too many arguments! @; @.;");
      } else {
         $Output->render("@#red: Argument invalid: `{$arguments[0]}`. @; @.;");
      }

      return true;
   }
}

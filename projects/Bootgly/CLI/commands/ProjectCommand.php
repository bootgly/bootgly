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


use Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\Terminal\components\Table\Table;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\Terminal\components\Alert\Alert;

class ProjectCommand extends Command
{
   // * Config
   public bool $separate = true;
   public int $group = 2;

   // * Data
   // @ Command
   public string $name = 'project';
   public string $description = 'Manage Bootgly projects';
   public array $arguments = [
      'list' => [
         'description' => 'List Bootgly projects',
         'options'     => [
            '--bootgly'
         ]
      ],
   ];


   public function run (array $arguments, array $options) : bool
   {
      return match ($arguments[0] ?? null) {
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
         ${'@'} = @include(BOOTGLY_ROOT_DIR . 'projects/@.php');
      } else {
         ${'@'} = @include(BOOTGLY_WORKING_DIR . 'projects/@.php');  
      }

      $projects = @${'@'};
      if ( ! empty($projects['projects']) ) {
         $Output->render('@.;@#cyan: Project list: @; @.;');
      } else {
         $Output->render('@.;@#red: Project list is empty: @; @.;');
      }

      foreach ($projects['projects'] as $index => $project) {
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

      // @
      $output = '';

      if ( empty($arguments) ) {
         $Output->write(PHP_EOL);

         $Table = new Table($Output);
         // * Data
         $Table->borders = Table::NO_BORDER_STYLE;
         // > Columns
         // * Config
         $Table->Columns->Autowiden::BASED_ON_SECTION->set();

         $Table->Data->set(header: [
            TemplateEscaped::render('@#Yellow: Arguments: @;'),
            ''
         ]);

         $body = [];
         foreach ($this->arguments as $name => $value) {
            $body[] = [
               TemplateEscaped::render('@#Green:' . $name . '@;'),
               TemplateEscaped::render($value['description'])
            ];
         }
         $Table->Data->set(body: $body);

         $Table->render();
      } else if ( count($arguments) > 1 ) {
         $Alert = new Alert($Output);
         $Alert->Type::FAILURE->set();
         $Alert->emit('Too many arguments!');
      } else {
         $Alert = new Alert($Output);
         $Alert->Type::FAILURE->set();
         $Alert->emit("Invalid argument: @#cyan:{$arguments[0]}@;.");
      }

      $output .= '@.;';

      $Output->render($output);

      return true;
   }
}

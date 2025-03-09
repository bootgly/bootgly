<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly;


use Bootgly;
use Bootgly\ABI\Data\__String\Path;
use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Fieldset;
use Bootgly\CLI\UI\Components\Header;

if ($this instanceof Bootgly\CLI !== true) {
   return;
}

// $Commands, $Scripts, $Terminal availables...
$Commands = CLI->Commands;

// @ Set Commands Helper
$Commands->register(Command: $Help = new class extends Command
{
   // * Config
   public int $group = 0;

   // * Data
   public string $name = 'help';
   public string $description = 'Display this help message';


   public function run (array $arguments = [], array $options = []): bool
   {
      // !
      $options = $this->options; // Replace with the global command options definition
      $verbosity = $this->verbosity;
      $message = $this->input;

      $Output = CLI->Terminal->Output;
      $output = "@.;";

      // @
      // # Banner
      $Header = new Header($Output);
      $output .= $Header
         ->generate(word: 'Bootgly', inline: true)
         ->render($Header::RETURN_OUTPUT);
      $output .= "@.;";
      $Output->render($output);

      $output = '';

      // # Command arguments
      $Fieldset1 = new Fieldset($Output);
      $Fieldset1->title = '@#Cyan: Commands arguments @;';
      // * Data
      $commands_list = [];
      // * Metadata
      $group = 0;
      $largest_command_name = 0;
      // !
      $Commands = CLI->Commands;
      foreach ($Commands->list(null) as $namespace => $commands) {
         foreach ($commands as $Command) {
            $command_name_length = \strlen($Command->name);
            if ($largest_command_name < $command_name_length) {
               $largest_command_name = $command_name_length;
            }

            $command = [
               // * Config
               'separate'    => $Command->separate ?? false,
               'group'       => $Command->group ?? null,

               // * Data
               'name'        => $Command->name,
               'description' => $Command->description,

               // * Metadata
               'isGlobal'    => $namespace === 'Bootgly\CLI\Commands',
            ];

            $commands_list[] = $command;
         }
      }
      // @
      foreach ($commands_list as $command) {
         // @ Config
         if ($command['separate'] === true) {
            $output .= '@---;';
         }
         if ($command['group'] > $group) {
            $group = $command['group'];
            $output .= PHP_EOL;
         }
         $command['isGlobal'] ? $color = '@#Green:' : $color = '@#Yellow:';

         $output .=  $color . \str_pad($command['name'], $largest_command_name + 2) . ' @; ';
         $output .= $command['description'] . PHP_EOL;
      }

      $output = \rtrim($output);
      $Fieldset1->content = $output;
      $Fieldset1->render();

      // # Options
      $output = '';
      $largest_option_name = 0;
      foreach ($options as $option_description => $option_names) {
         $option_name_length = \strlen(\implode(', ', $option_names));
         if ($largest_option_name < $option_name_length) {
            $largest_option_name = $option_name_length;
         }
      }
      foreach ($options as $option_description => $option_names) {
         $output .= '@#Yellow:' . \str_pad(\implode(', ', $option_names), $largest_option_name + 2) . ' @; ';
         $output .= $option_description . PHP_EOL;
      }
      $output = trim($output);
      $Fieldset2 = new Fieldset($Output);
      $Fieldset2->title = '@#Cyan: Commands options@;';
      $Fieldset2->content = $output;
      $Fieldset2->width = $Fieldset1->width;
      $Fieldset2->render();

      // # Message
      if ($message) {
         $Fieldset3 = new Fieldset($Output);
         $Fieldset3->title = '@#Red: helper message@;';
         $Fieldset3->content = $message;
         $Fieldset3->width = $Fieldset1->width;
         $Fieldset3->render();
      }

      // # Script usage
      $script = $this->script;
      $script = match ($script[0]) {
         '/'     => new Path($script)->current,
         '.'     => $script,
         default => 'php ' . $script
      };
      $usage = $script . ' @#Black: [arguments] [...options] @;';
      if ($verbosity >= 1) {
         $usage .= <<<OUTPUT
            @..;Example:
         @#Black:{$script} serve
         OUTPUT;
      }

      $Fieldset4 = new Fieldset($Output);
      $Fieldset4->title = '@#Cyan:Commands usage@;';
      $Fieldset4->content = $usage;
      $Fieldset4->width = $Fieldset1->width;
      $Fieldset4->render();

      // # Versions (Bootgly, PHP)
      $PHP = \PHP_VERSION;
      $Bootgly = \BOOTGLY_VERSION;

      $Output->pad(<<<OUTPUT
         @#Black:Bootgly @_:v{$Bootgly} @; | @#Black:PHP @_:v{$PHP} @;@..;
         OUTPUT,
         $Fieldset1->width + 5,
         " ",
         \STR_PAD_LEFT
      );

      return true;
   }
}, Script: $Commands);

// @ Register commands
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   $Commands->register($Command, Script: $this);
}

// @ Route commands
$Commands->route(From: $this);

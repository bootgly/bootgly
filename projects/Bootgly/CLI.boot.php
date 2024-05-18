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


use Bootgly\ABI\Data\__String\Path;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Fieldset\Fieldset;
use Bootgly\CLI\UI\Header;


// $Commands, $Scripts, $Terminal availables...
$Commands = CLI->Commands;

// @ Set Commands Helper
$Commands->help(function ($scripting = true) {
   $Output = CLI->Terminal->Output;

   $script = $this->args[0];
   $script = match ($script[0]) {
      '/'     => (new Path($script))->current,
      '.'     => $script,
      default => 'php ' . $script
   };

   $output = '@.;';
   if ($scripting) {
      // @ Banner
      $Header = new Header($Output);
      $output .= $Header
         ->generate(word: 'Bootgly', inline: true)
         ->render($Header::RETURN_OUTPUT);

      // @ Usage
      $output .= '@.;@#Cyan:Usage:@; ' . $script . '@#Black:  [command] @;@..;';
   }
   $Output->render($output);

   // @ Command list
   $Fieldset = new Fieldset($Output);
   $Fieldset->title = '@#Cyan: Available commands: @;';

   // * Data
   $commands = [];
   // * Metadata
   $largest_command_name = 0;
   // @
   foreach ($this->commands as $Command) {
      $command_name_length = strlen($Command->name);
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
      ];

      $commands[] = $command;
   }

   // * Data
   $output = '';
   // * Metadata
   $group = 0;
   foreach ($commands as $command) {
      // @ Config
      if ($command['separate']) {
         $output .= '@---;';
      }
      if ($command['group'] > $group) {
         $group = $command['group'];
         $output .= PHP_EOL;
      }

      $output .= '@#Yellow:' . str_pad($command['name'], $largest_command_name + 2) . ' @; ';
      $output .= $command['description'] . PHP_EOL;
   }

   // :
   $output = rtrim($output);
   $Fieldset->content = $output;
   $Fieldset->render();
});

// @ Register commands
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   $Commands->register($Command);
}

// @ Route commands
$Commands->route();

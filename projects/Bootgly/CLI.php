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


use Bootgly\CLI;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\components\Header;
use Bootgly\CLI\Terminal\components\Field\Field;

// $Commands, $Scripts, $Terminal availables...

// @ Set Commands Helper
CLI::$Commands->help(function ($scripting = true) {
   $Output = CLI::$Terminal->Output;

   $script = $this->args[0];
   $script = match ($script[0]) {
      '/'     => (new Path($script))->current,
      '.'     => $script,
      default => 'php ' . $script
   };

   $output = '@.;';
   if ($scripting) {
      // @ Banner
      $Header = new Header;
      $output .= $Header->generate(word: 'Bootgly', inline: true);

      // @ Usage
      $output .= '@.;Usage: ' . $script . '@#Black:  [command] @;@..;';
   }
   $Output->render($output);

   // @ Command list
   $Field = new Field($Output);
   $Field->title = 'Available commands:';

   // * Data
   $commands = [];
   // * Meta
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
   // * Meta
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
   $content = TemplateEscaped::render($output);
   $Field->render($content);
});
// @ Register commands
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}
// @ Route commands
CLI::$Commands->route();

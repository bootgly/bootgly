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
      $output .= '@.;Usage: ' . $script . ' [command] @..;';
   }
   $Output->render($output);

   // @ Command list
   $Field = new Field($Output);
   $Field->title = 'Available commands:';

   $output = '';

   // * Data
   $commands = [];
   // * Meta
   $max_command_name_length = 0;
   // @
   foreach ($this->commands as $Command) {
      $command_name_length = strlen($Command->name);
      if ($max_command_name_length < $command_name_length) {
         $max_command_name_length = $command_name_length;
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

      // @ Data
      $name = $command['name'];

      $output .= '@#Yellow:' . str_pad($name, $max_command_name_length + 2) . ' @; = ';
      $output .= $command['description'] . PHP_EOL;
   }

   $Field->render(TemplateEscaped::render($output));
});
// ---
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}
CLI::$Commands->route();

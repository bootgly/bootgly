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


// $Commands, $Scripts, $Terminal availables...

// @ Set Commands Helper
CLI::$Commands->help(function ($scripting = true) {
   $output = '@.;';

   $script = $this->args[0];
   $script = match ($script[0]) {
      '/'     => (new Path($script))->current,
      '.'     => $script,
      default => 'php ' . $script
   };

   if ($scripting) {
      // @ Header
      $Header = new Header;
      $output .= $Header->generate(word: 'Bootgly', inline: true);

      // @ Usage
      $output .= '@.;Usage: ' . $script . ' [command] @..;';

      // @ Command list
      $output .= 'Available commands:';
   }

   $output .= PHP_EOL . str_repeat('=', 70) . PHP_EOL;

   // * Data
   $commands = [];
   // * Meta
   $maxCommandNameLength = 0;
   // @
   foreach ($this->commands as $Command) {
      $commandNameLength = strlen($Command->name);
      if ($maxCommandNameLength < $commandNameLength) {
         $maxCommandNameLength = $commandNameLength;
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
         $output .= str_repeat('-', 70);
      }
      if ($command['group'] > $group) {
         $group = $command['group'];
         $output .= PHP_EOL;
      }

      // @ Data
      $name = '`' . $command['name'] . '`';

      $output .= '@:i: ' . str_pad($name, $maxCommandNameLength + 2) . ' @; = ';
      $output .= $command['description'] . PHP_EOL;
   }

   $output .= str_repeat('=', 70) . PHP_EOL . PHP_EOL;

   echo TemplateEscaped::render($output);
});
// ---
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}
CLI::$Commands->route();

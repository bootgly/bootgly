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


use const Bootgly\CLI;
use Bootgly\CLI\UI\Fieldset\Fieldset;
use Bootgly\CLI\UI\Header;


// $Commands, $Scripts, $Terminal availables...
$Commands = CLI->Commands;

// @ Set Commands Helper
$Commands->Helper = (function (? string $banner = null, ? string $message = null, string $script) {
   // !
   $Output = CLI->Terminal->Output;

   $output = "@.;";

   // @
   // # Banner
   if ($banner === null) {
      $Header = new Header($Output);
      $output .= $Header
         ->generate(word: 'Bootgly', inline: true)
         ->render($Header::RETURN_OUTPUT);
      $output .= "@.;";
   }
   $Output->render($output);

   $output = '';
   // # Command list
   $Fieldset1 = new Fieldset($Output);
   $Fieldset1->title = '@#Cyan: commands @;';
   // * Data
   $commands = [];
   // * Metadata
   $group = 0;
   $largest_command_name = 0;
   // !
   foreach ($this->commands as $Command) {
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
      ];

      $commands[] = $command;
   }
   // @
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
   $output .= \rtrim($output);
   $Fieldset1->content = $output;
   $Fieldset1->render();

   // # Command Options list
   // TODO

   // # Message
   if ($message) {
      $Fieldset2 = new Fieldset($Output);
      $Fieldset2->title = '@#Red:helper message@;';
      $Fieldset2->content = $message;
      $Fieldset2->width = $Fieldset1->width;
      $Fieldset2->render();
   }

   // # Script usage
   if ($script) {
      $Fieldset3 = new Fieldset($Output);
      $Fieldset3->title = '@#Cyan:usage@;';
      $Fieldset3->content = $script . ' @#Black: [command] @;';
      $Fieldset3->width = $Fieldset1->width;
      $Fieldset3->render();
   }

   // # Versions (Bootgly, PHP)
   $PHP = \PHP_VERSION;
   $Bootgly = \BOOTGLY_VERSION;

   $Output->pad(<<<OUTPUT
      @#Black:Bootgly @_:v{$PHP} @; | @#Black:PHP @_:v{$Bootgly} @;@..;
      OUTPUT,
      $Fieldset1->width + 5,
      " ",
      \STR_PAD_LEFT
   );
});

// @ Register commands
$commands = require('CLI/commands/@.php');
foreach ($commands as $Command) {
   $Commands->register($Command);
}

// @ Route commands
$Commands->route();

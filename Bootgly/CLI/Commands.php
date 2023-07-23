<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Closure;
use Bootgly\ABI\templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\components\Header;


class Commands
{
   // * Config
   // ...

   // * Data
   public array $args;
   public array $commands;
   // ...

   // * Meta
   // ...


   public function __construct ()
   {
      // * Data
      $this->args = $_SERVER['argv'] ?? [];
      $this->commands = [
         'help' => new class extends Command
         {
            public string $name = 'help';
            public string $description = 'Show this help message';


            public function run (array $arguments, array $options) : bool
            {
               // TODO
               return true;
            }
         }
      ];
   }

   public function register (Commanding|Command|Closure $Command, string $name = '', string $description = '') : bool
   {
      if ($Command instanceof Command || $Command instanceof Commanding) {
         $this->commands[] = $Command;
     } elseif ($Command instanceof Closure) {
         $Command = new class ($Command, $name, $description) extends Command
         {
            public Closure $Command;

            public string $name;
            public string $description;


            public function __construct (Closure $Command, string $name, string $description)
            {
               $this->Command = $Command;

               $this->name = $name;
               $this->description = $description;
            }
            public function run (array $arguments, array $options) : bool
            {
               return ($this->Command)($arguments, $options);
            }
         };
         $this->commands[] = $Command;
     }

      return true;
   }

   public function route () : bool
   {
      // Argments
      $args = $this->args;

      // @ Verify if a command was provided
      if (count($args) < 2) {
         $this->help();
         return false;
      }

      // Command
      // @ Get the name of the command to run
      $name = $args[1];

      // @ Search for the corresponding command
      $Command = $this->find($name);
      if ($Command === null) {
         echo "Unknown command: $name" . PHP_EOL;
         $this->help();
         return false;
      }

      // @ Remove the command name from the arguments
      $args = array_slice($args, 2);

      // TODO move to Commands\Arguments?
      // @ Process options and argments
      $options = [];
      $arguments = [];

      foreach ($args as $arg) {
         if (strpos($arg, '--') === 0) {
            // Option (--op1[=val1])
            $optionParts = explode('=', substr($arg, 2), 2);
            $optionName = $optionParts[0];
            $optionValue = isSet($optionParts[1]) ? $optionParts[1] : true;

            $options[$optionName] = $optionValue;
         } elseif (strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $optionNames = str_split(substr($arg, 1));

            foreach ($optionNames as $optionName) {
               $options[$optionName] = true;
            }
         } else {
            // Argument
            $arguments[] = $arg;
         }
      }

      // @ Run the command
      $Command->run($arguments, $options);

      return true;
   }

   public function find (string $name) : Commanding|Command|null
   {
      foreach ($this->commands as $Command) {
         if ($Command->name === $name) {
            return $Command;
         }
      }

      return null;
   }

   private function help (bool $scripting = true) : void
   {
      $help = '@.;';

      if ($scripting) {
         // @ Header
         $Header = new Header;
         $help .= $Header->generate(word: 'Bootgly', inline: true);

         // @ Usage
         $script = match ($this->args[0]) {
            '/usr/local/bin/bootgly' => 'bootgly',
            './bootgly'              => './bootgly',
            'bootgly'                => 'php bootgly'
         };
         $help .= '@.;Usage: ' . $script . ' [command] @..;';

         // @ Command list
         $help .= 'Available commands:';
      }

      $help .= PHP_EOL . str_repeat('=', 70) . PHP_EOL;

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
            $help .= str_repeat('-', 70);
         }
         if ($command['group'] > $group) {
            $group = $command['group'];
            $help .= PHP_EOL;
         }

         // @ Data
         $name = '`' . $command['name'] . '`';

         $help .= '@:i: ' . str_pad($name, $maxCommandNameLength + 2) . ' @; = ';
         $help .= $command['description'] . PHP_EOL;
      }

      $help .= str_repeat('=', 70) . PHP_EOL . PHP_EOL;

      echo TemplateEscaped::render($help);
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Bootgly\templates\ANSI\Escaped;


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
      $this->args = $argv ?? $_SERVER['argv'] ?? [];
      $this->commands = [
         'help' => new class extends Command
         {
            public string $name = 'help';
            public string $description = 'Show this help message';

            public function run (array $arguments, array $options)
            {
               // TODO
            }
         }
      ];
   }

   public function register (Command $Command)
   {
      $this->commands[] = $Command;
   }

   public function route ()
   {
      // Argments
      $values = $this->args;

      // @ Verify if a command was provided
      if (count($values) < 2) {
         $this->help();
         return;
      }

      // Command
      // @ Get the name of the command to run
      $name = $values[1];

      // @ Search for the corresponding command
      $command = $this->find($name);
      if ($command === null) {
         echo "Unknown command: $name" . PHP_EOL;
         $this->help();
         return;
      }

      // @ Remove the command name from the arguments
      $args = array_slice($values, 2);

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
      $command->run($arguments, $options);
   }

   public function find (string $name) : Command|null
   {
      foreach ($this->commands as $Command) {
         if ($Command->name === $name) {
            return $Command;
         }
      }

      return null;
   }

   public function help ()
   {
      $help = '@.;Usage: php ' . $this->args[0] . ' [command] @..;';
      $help .= 'Available commands: @.;';

      $help .= '@.;======================================================================' . PHP_EOL;

      foreach ($this->commands as $Command) {
         $help .= '@:i: `' . $Command->name . '` @; = ' . $Command->description . PHP_EOL;
      }

      $help .= '========================================================================' . PHP_EOL;

      echo Escaped::render($help);
   }
}

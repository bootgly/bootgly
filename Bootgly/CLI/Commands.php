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


use function count;
use function is_array;
use Closure;

use const BOOTGLY_ROOT_DIR;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\CLI\Commands\Arguments;


class Commands
{
   // * Config
   // ...

   // * Data
   protected ?string $banner;
   // # Command
   /** @var array<string,array<Command>|Command> */
   protected array $commands;
   // # Commands/Arguments
   protected ?Closure $Helper;
   // ...

   // * Metadata
   // # Command
   private string $script;
   // ...


   public function __construct (
      public Arguments $Arguments = new Arguments
   )
   {
      // * Config
      // ...

      // * Data
      $this->banner = null;
      // # Command
      $this->commands = [];

      // * Metadata
      // ...
   }

   /**
    * Load commands from a location
    *
    * @param string $location
    * @param object|null $Context
    * @param object|null $Script
    *
    * @return bool
    */
   public function autoload (string $location, ?object $Context = null, ?object $Script = null): bool
   {
      // !?
      $commands = require Path::normalize(BOOTGLY_ROOT_DIR . $location . '/commands/@.php');
      if ($commands === false || !is_array($commands) || count($commands) === 0) {
         return false;
      }

      // @
      foreach ($commands as $namespace => $command) {
         // # Pre command load
         // TODO

         // # Post command load
         $command = require $command;

         if (is_array($command) === true) {
            $Command = $command['handle'];

            unset($command['handle']);
            $specification = $command;
            $specification['context'] = $Context;

            $this->register($Command, $specification, $Script);
         }
         else if ($command instanceof Command) {
            $command($Context);

            $this->register($command, ['context' => $Context], $Script);
         }
      }

      return true;
   }
   /**
    * Register a command
    * 
    * @param Command|Closure $Command
    * @param array<string, object|null> $specification
    * @param object|null $Script
    *
    * @return bool
    */
   public function register (
      Command|Closure $Command,
      array $specification = [],
      ?object $Script = null
   ): bool
   {
      if ($Command instanceof Closure) {
         /** 
          * @var string|null $name
          * @var string|null $description
          * @var array<string> $arguments
          * @var array<string> $options
          * @var object|null $context
          */
         [
            'name' => $name,
            'description' => $description,
            'arguments' => $arguments,
            'options' => $options,
            'context' => $context
         ] = $specification;

         $Command = new class (
            // * Config
            $name, $description, $arguments, $options, $context,
            // * Data
            $Command
         ) extends Command
         {
            public function run (array $arguments = [], array $options = []): bool
            {
               return ($this->Command)($arguments, $options);
            }
         };
      }

      if ($Script === null) {
         $Script = $this;
      }

      $this->commands[$Script::class][] = $Command;

      return true;
   }

   /**
    * List commands from a namespace or all commands
    *
    * @param object|null $From
    *
    * @return array<Command>
    */
   public function list (?object $From = null): array
   {
      // ?!
      if ($From === null) {
         return $this->commands;
      }

      $commands = [];

      foreach ($this->commands as $namespace => $Commands) {
         if ($namespace !== $From::class) {
            continue;  
         }

         foreach ($Commands as $Command) {
            $commands[] = $Command;
         }
      }

      return $commands;
   }
   /**
    * Find a command by its name (with namespace if provided) and return it if found
    *
    * @param ?string $command
    * @param object|null $From
    *
    * @return Command|null
    */
   public function find (
      ?string $command,
      ?object $From = null,
      ?string $input = null
   ): Command|null
   {
      if ($command === null || $command === '') {
         return null;
      }

      /** @var Command $Command */
      foreach ($this->list($From) as $Command) {
         if ($Command->name === $command) {
            $Command->input = $input;

            return $Command;
         }
      }

      return null;
   }

   /**
    * Route a command by its signature to the corresponding command.
    * 
    * The command signature is an array containing the script name, the command name
    * and the command arguments (used to programmaticaly command route).
    * 
    * If no command is provided, the command signature is taken from native PHP CLI arguments.
    * If no command is found, the help message is shown.
    *
    * @param array<string>|null $command
    * @param object|null $From
    *
    * @return bool
    */
   public function route (?array $command = null, ?object $From = null): bool
   {
      // @ Parse arguments and options
      [$script, $arguments, $options] = $this->Arguments->parse(
         $command ?? $_SERVER['argv'] ?? []
      );

      $this->script = $script;
      $command = $arguments[0] ?? null;

      // @ Get the command or get the help command
      /** @var Command $Command */
      $Command = $this->find(
         $command,
         $command === 'help' ? $this : $From
      )
         ?? $this->find(
            command: 'help',
            From: $this,
            input: $command !== null
               ? "Unknown command: @#Yellow:$command@;"
               : null
         );

      // @ Prerun the command
      // Parse arguments
      $arguments = array_slice($arguments, 1);

      // Parse verbosity options
      if ($options['v'] ?? false) {
         $verbosity = $options['v'];
         if ($verbosity > 3) {
            $verbosity = 3;
         }
         unset($options['v']);

         $Command->verbosity = $verbosity;
      }

      // @ Run the command
      $Command->run($arguments, $options);

      return true;
   }
}

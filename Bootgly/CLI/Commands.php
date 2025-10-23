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
use Error;

use const BOOTGLY_ROOT_DIR;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\CLI\Command;
use Bootgly\CLI\Commands\Arguments;


class Commands
{
   // * Config
   // ...

   // * Data
   protected null|string $banner;
   // # Command
   /** @var array<string,array<Command>> */
   protected array $commands;
   // # Commands/Arguments
   protected null|Closure $Helper;
   // ...

   // * Metadata
   // # Command
   protected string $script;
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
   public function autoload (
      string $location,
      null|object $Context = null,
      null|object $Script = null
   ): bool
   {
      // !?
      $commands = require Path::normalize(BOOTGLY_ROOT_DIR . $location . '/commands/@.php');
      if (
         $commands === false
         || !is_array($commands)
         || count($commands) === 0
      ) {
         return false;
      }

      // @
      foreach ($commands as $namespace => $command) {
         // # Pre command load
         // TODO

         // # Post command load
         // !
         $Command = require $command;
         // ?
         if ($Command instanceof Command === false) {
            throw new Error("Command must be an instance of Command.");
         }

         $Command($Context);

         $this->register($Command, $Script);
      }

      return true;
   }
   /**
    * Register a command
    * 
    * @param Command $Command
    * @param null|object $Script
    *
    * @return bool
    */
   public function register (
      Command $Command,
      null|object $Script = null,
      null|object $Context = null
   ): bool
   {
      if ($Script === null) {
         $Script = $this;
      }

      if ($Context !== null) {
         $Command($Context);
      }

      $this->commands[$Script::class][] = $Command;

      return true;
   }

   /**
    * List commands from a namespace or all commands
    *
    * @param null|object $From
    *
    * @return array<Command>|array<string,array<Command>>
    */
   public function list (null|object $From = null): array
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
    * @param null|string $command
    * @param object|null $From
    *
    * @return Command|null
    */
   public function find (
      null|string $command,
      null|object $From = null,
      null|string $input = null
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
    * @param array<string>|null $route
    * @param object|null $From
    *
    * @return bool
    */
   public function route (null|array $route = null, null|object $From = null): bool
   {
      // !
      // # Signature
      /** @var array<string> $argv */
      $argv = (array) $_SERVER['argv'];
      [
         $this->script,
         $command,
         $arguments,
         $options
      ] = $this->Arguments->parse(
         $route ?? $argv
      );

      // @ Get the command | help command
      /** @var Command $Command */
      $Command = $this->find(
         $command,
         $command === 'help'
            ? $this
            : $From
      ) ?? $this->find(
         command: 'help',
         From: $this,
         input: $command !== ''
            ? "Unknown command: @#Yellow:$command@;"
            : null
      );

      $Command->script ??= $this->script;

      // @ Prerun the command
      // Parse verbosity options
      if ($options['v'] ?? false) {
         $verbosity = (int) $options['v'];
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

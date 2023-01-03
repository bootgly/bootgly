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


use Bootgly\CLI\_\ {
   Logger\Logging
};


// TODO implements or extend CLI/Command?
class Console
{
   use Logging;

   // ! Command
   // * Data
   public static array $commands = [];
   // * Meta
   public static array $command = []; // @ Last command used (returned by autocomplete)

   // ***


   // TODO support to multiple subcommands (command1 subcommand1 subcommand2...)
   public function autocomplete (string $search) : array
   {
      $commands = [];

      if ($search || count(self::$command) === 0) {
         $commands = array_filter(static::$commands, function ($command) use ($search) {
            return preg_match("/$search/i", $command);
         });
      }

      if (count($commands) === 1 && count(self::$command) === 0) {
         array_push(self::$command, $commands);
      }

      return $commands;
   }

   public function clear ()
   {
      $this->log(chr(27).chr(91).'H'.chr(27).chr(91).'J');
      return true;
   }
}

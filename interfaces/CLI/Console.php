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
   public static array $subcommands = [];
   // * Meta
   public static array $command = []; // @ Last command used (returned by autocomplete)

   // ***


   // TODO support to multiple subcommands (command1 subcommand1 subcommand2...)
   public function autocomplete (string $search) : array // return commands found
   {
      $found = [];

      // TODO refactor
      if ($search || count(self::$command) === 0) {
         $found = array_filter(static::$commands, function ($command) use ($search) {
            $command = preg_quote($command, '/');
            return preg_match("/$search/i", $command);
         });
      } else if (count(self::$command) === 1) {
         $found = array_filter(static::$subcommands[self::$command[0]], function ($command) use ($search) {
            $command = preg_quote($command, '/');
            return preg_match("/$search/i", $command);
         });
      }

      if (count($found) === 1) {
         array_push(self::$command, ...$found);
      }

      return $found;
   }

   public function clear ()
   {
      $this->log(chr(27).chr(91).'H'.chr(27).chr(91).'J');
      return true;
   }
}

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


class Terminal
{
   // * Config
   // * Data
   public $stream = STDOUT;
   // ! Command
   public static array $commands = [];
   public static array $subcommands = [];
   // * Meta
   public int $width;
   // ! Command
   public static array $command = []; // @ Last command used (returned by autocomplete)


   public function __construct ($stream = STDOUT)
   {
      // * Data
      $this->stream = $stream;
      // * Meta
      // width
      // @ Get the terminal width
      $this->width = exec("tput cols 2>/dev/null");
      if ( ! is_numeric($this->width) ) {
         $this->width = 80;
      }

   }

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

   public function output (string $data) : int|false
   {
      return fwrite($this->stream, $data);
   }
   public function clear ()
   {
      $this->output(chr(27).chr(91).'H'.chr(27).chr(91).'J');
      return true;
   }
}
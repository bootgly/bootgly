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


// @resources
// cursor
require 'Terminal/cursor/Positioning.php'; // @ trait
require 'Terminal/cursor/Visualizing.php'; // @ trait
// text
require 'Terminal/text/Formatting.php'; // @ trait
require 'Terminal/text/Modifying.php'; // @ trait


use Bootgly\CLI;
use Bootgly\CLI\Terminal\cursor;
use Bootgly\CLI\Terminal\text;


class Terminal
{
   use cursor\Visualizing;
   use cursor\Positioning;
   use text\Formatting;
   use text\Modifying;


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

   // ! Command
   // If return true -> interact imediatily in the next loop otherwise wait for output...
   public function interact () : bool
   {
      // @ Register CLI autocomplete function
      // Use TAB key as trigger
      readline_completion_function([$this, 'autocomplete']);

      // @ Get user input (read line)
      $input = readline('>_: ');

      // @ Sanitize user input
      $command = trim($input);

      if ($command === '') return true;

      // @ Clear last used command (returned by autocomplete function)
      self::$command = [];

      // @ Enable command history and add the last command to history
      // Use UP/DOWN key to access the history
      readline_add_history($command);

      // @ Execute command
      return $this->command($command);
   }
   public function command (string $command): bool
   {
      // TODO default
      return true;
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
      $this->output(
         CLI::_START_ESCAPE . self::_CURSOR_POSITION .
         CLI::_START_ESCAPE . self::_TEXT_ERASE_IN_DISPLAY
      );
      return true;
   }
}

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


use Bootgly\CLI;

use Bootgly\CLI\Escaping\cursor\Positioning;
use Bootgly\CLI\Escaping\text\Modifying;

use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


class Terminal
{
   use Positioning;
   use Modifying;


   // * Config
   // ...

   // * Data
   // ! Command
   public static array $commands = [];
   public static array $subcommands = [];

   // * Meta
   public static int $width;
   public static int $height;

   public static int $columns;
   public static int $lines;
   // ! Command
   public static array $command = []; // @ Last command used (returned by autocomplete)


   // ! IO
   // ? Input
   public Input $Input;
   // ? Output
   public Output $Output;
   public Output\Cursor $Cursor;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // ...

      // * Meta
      // columns
      // @ Get the terminal columns (width)
      $columns = exec("tput cols 2>/dev/null");
      if ( ! is_numeric($columns) ) {
         $columns = 80;
      }
      self::$columns = $columns;
      // lines
      // @ Get the terminal lines (height)
      $lines = exec("tput lines 2>/dev/null");
      if ( ! is_numeric($lines) ) {
         $lines = 30;
      }
      self::$lines = $lines;
      // width
      self::$width = self::$columns;
      // height
      self::$height = self::$lines;


      // ! IO
      // ? Input
      $this->Input = new Input;
      // ? Output
      $this->Output = new Output;
      $this->Cursor = &$this->Output->Cursor;
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

      if ($command === '') {
         return true;
      }

      // @ Clear last used command (returned by autocomplete function)
      self::$command = [];

      // @ Enable command history and add the last command to history
      // Use UP/DOWN key to access the history
      readline_add_history($command);

      // @ Execute command
      return $this->command($command);
   }
   protected function command (string $command) : bool
   {
      // TODO default
      return true;
   }

   // TODO support to multiple subcommands (command1 subcommand1 subcommand2...)
   protected function autocomplete (string $search) : array // return commands found
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

   public function clear () : true
   {
      $this->Output->write(
         CLI::_START_ESCAPE . self::_CURSOR_POSITION .
         CLI::_START_ESCAPE . self::_TEXT_ERASE_IN_DISPLAY
      );

      return true;
   }
}

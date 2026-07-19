<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use function array_filter;
use function array_push;
use function count;
use function preg_match;
use function preg_quote;
use function readline;
use function readline_add_history;
use function readline_completion_function;
use function trim;

use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Reporting\Mouse;
use Bootgly\CLI\Terminal\Screen;


class Terminal // extends API/Project or API/Node
{
   use Escapeable;
   use Positionable;
   use Modifiable;


   // * Config
   // ...

   // * Data
   // ! Command
   /** @var array<string> */
   public static array $commands = [];
   /** @var array<string,array<string>> */
   public static array $subcommands = [];

   // * Metadata
   public static int $width;
   public static int $height;

   public static int $columns;
   public static int $lines;
   // ! Command
   /** @var array<string> */
   public static array $command = []; // @ Last command used (returned by autocomplete)
   // ! Instance
   /** The booted Terminal — embedded outputs (e.g. a Wizard Region) swap $Output through this handle */
   public static Terminal $Terminal;


   // ! IO
   // ? Input
   public Input $Input;
   // ? Output
   public Output $Output;
   // ? Screen
   public Screen $Screen;
   // ! Reporting
   public Mouse $Mouse;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // @ Measure the terminal size (canonical probe: environment → tput → fallback)
      [$columns, $lines] = Screen::measure();
      // columns
      self::$columns = $columns;
      // lines
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
      // ? Screen
      $this->Screen = new Screen($this->Output);
      // ! Reporting
      $this->Mouse = new Mouse($this->Input, $this->Output);

      // ! Instance
      self::$Terminal = $this;
   }

   // ! Command
   // If return true -> interact imediatily in the next loop otherwise wait for output...
   public function interact (): bool
   {
      $this->prepare();

      // @ Get user input (read line)
      $input = readline('>_: ');
      if ($input === false) {
         return false;
      }

      return $this->execute($input);
   }

   /** Register the readline completion callback. */
   public function prepare (): void
   {
      readline_completion_function([$this, 'autocomplete']);
   }

   /** Execute one complete interactive input line. */
   public function execute (string $input): bool
   {
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
   protected function command (string $command): bool
   {
      // TODO: default
      return true;
   }

   /**
    * Autocomplete to Terminal commands
    * 
    * @param string $search
    *
    * @return array<string>
    */
   protected function autocomplete (string $search): array
   {
      // TODO: support to multiple subcommands (command1 subcommand1 subcommand2...)
      $found = [];

      $filterCommands = function ($commands)
      use ($search): array {
         return array_filter(
            $commands,
            function ($command) use ($search) {
               $command = preg_quote($command, '/');
               $found = preg_match("/$search/i", $command);
               return $found === 1;
            }
         );
      };

      if ($search || count(self::$command) === 0) {
         $found = $filterCommands(static::$commands);
      }
      else if (count(self::$command) === 1) {
         $found = $filterCommands(static::$subcommands[self::$command[0]]);
      }

      if (count($found) === 1) {
         array_push(self::$command, ...$found);
      }

      return $found;
   }

   public function clear (): true
   {
      $this->Output->write(
         self::_START_ESCAPE . self::_CURSOR_POSITION .
         self::_START_ESCAPE . self::_TEXT_ERASE_IN_DISPLAY
      );

      return true;
   }
}

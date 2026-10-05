<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const SIGALRM;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGWINCH;
use const STDIN;
use function array_filter;
use function array_push;
use function count;
use function feof;
use function fread;
use function function_exists;
use function hrtime;
use function is_resource;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function preg_match;
use function preg_quote;
use function readline;
use function readline_add_history;
use function readline_callback_handler_install;
use function readline_callback_handler_remove;
use function readline_callback_read_char;
use function readline_completion_function;
use function stream_isatty;
use function stream_select;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function usleep;
use Closure;
use Generator;
use Throwable;

use Bootgly\ABI\Code\__String\Escapeable;
use Bootgly\ABI\Code\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Modifiable;
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
   // ! Prompt
   /** Whether the readline callback handler of `prompting()` is installed */
   public private(set) bool $armed = false;
   /**
    * Whether `prompting()` edits the line through readline (TAB completion,
    * recall of the lines passed to `execute()`): stdin is a terminal and
    * ext-readline is loaded.
    */
   public bool $editing {
      get => is_resource(STDIN)
         && stream_isatty(STDIN)
         && function_exists('readline_callback_handler_install');
   }
   /** Plain (non-terminal) input read but not yet yielded — kept across `prompting()` calls */
   private string $buffer = '';
   /** Whether the rest of an over-long plain line is being dropped, up to its end */
   private bool $discarding = false;


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
   /**
    * Read and execute one command line, blocking until it is typed (needs
    * ext-readline). A caller that must keep supervising while nobody types
    * uses `prompting()`.
    *
    * @return bool Whether the caller may prompt again at once (false: the
    *              input is closed, or the command answers asynchronously).
    */
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
   /**
    * Prompt for command lines without blocking the caller while it waits.
    *
    * `$Supervise` runs before every wait for input and returns how long that
    * wait may last in microseconds (`0` polls), or `false` to end the prompt;
    * any signal cuts a wait short. Each complete line is yielded with the
    * prompt disarmed — the caller executes it with the terminal in its own
    * mode — and the prompt is re-armed before the next wait.
    *
    * A terminal is edited through readline (TAB completion, recall of the
    * lines passed to `execute()`), and the half-typed line survives every
    * wait; its EOF (Ctrl-D on an empty line, a hangup) ends the prompt. On
    * libedit an unfinished ESC, ^V or ^R holds the wait until the next key —
    * a signal still cuts it short. Without ext-readline a terminal is read as
    * plain lines under the same prompt, edited by the terminal alone (no
    * recall or completion). Any other stdin (a pipe, a file, `/dev/null`) is read as
    * plain lines without a prompt; at its EOF nobody is left to type, so the
    * prompt keeps running `$Supervise` without input.
    *
    * @param Closure(): (int|false) $Supervise
    *
    * @return Generator<int,string>
    */
   public function prompting (Closure $Supervise): Generator
   {
      // ! Input
      $terminal = stream_isatty(STDIN);
      /** @var null|resource $Stream null once a plain stdin is at EOF */
      $Stream = STDIN;
      // ? Readline edits terminals only: on anything else libedit never
      //   reports the EOF and turns the lines piped after a re-arm into ''
      $editing = $this->editing;
      // ! Whether a plain terminal shows the prompt for the line being typed
      $prompted = false;
      // ! Readline callback
      $line = null;
      $accepted = false;
      $Accept = static function (null|string $read) use (&$line, &$accepted): void {
         $line = $read;
         $accepted = true;
      };

      try {
         // @@ Supervise, then wait for input — never longer than allowed
         while (($timeout = $Supervise()) !== false) {
            // ? Plain input: one complete line per turn
            $end = strpos($this->buffer, "\n");
            if ($end === false && strlen($this->buffer) >= 65_536) {
               // ! A line past 64 KiB is no command: dropped whole, up to its
               //   end, so the buffer stays bounded
               $this->buffer = '';
               $this->discarding = true;
            }
            if ($end !== false) {
               $read = substr($this->buffer, 0, $end);
               $this->buffer = substr($this->buffer, $end + 1);

               // ? The end of a dropped over-long line
               if ($this->discarding) {
                  $this->discarding = false;

                  continue;
               }

               $prompted = false;
               yield $read;

               continue;
            }
            // ? Plain input at EOF: keep supervising without it
            if ($Stream === null) {
               if ($timeout > 0) {
                  usleep($timeout);
               }

               continue;
            }

            if ($editing && $this->armed === false) {
               $this->prepare();
               readline_callback_handler_install('>_: ', $Accept);
               $this->armed = true;
            }
            // @ A terminal without readline: the same prompt, once per line
            if ($terminal && $editing === false && $prompted === false) {
               $this->Output->write('>_: ');
               $prompted = true;
            }

            // @ Wait for input — a signal (no SA_RESTART) or the timeout ends it
            $Read = [$Stream];
            $Write = null;
            $Except = null;
            if (@stream_select($Read, $Write, $Except, 0, $timeout) < 1) {
               continue;
            }

            // # Terminal: readline consumes the keystrokes
            if ($editing) {
               $started = hrtime(true);
               readline_callback_read_char();
               // ! libedit's multi-key commands (^V, ^R, an ESC prefix) block
               //   inside this call for the next key
               $blocked = hrtime(true) - $started > 50_000_000;
               if ($accepted === false) {
                  // ?: A hung-up terminal stays readable and libedit never
                  //   delivers its EOF: end the prompt as Ctrl-D does
                  if (stream_isatty(STDIN) === false) {
                     return;
                  }

                  continue;
               }
               $accepted = false;

               $this->disarm();

               if ($line === null) {
                  // ? A signal that cut a blocked read short reads as EOF on
                  //   libedit: not a Ctrl-D — the prompt is re-armed next turn
                  if ($blocked && stream_isatty(STDIN)) {
                     continue;
                  }

                  // ?: Ctrl-D on an empty line, or a hangup
                  return;
               }

               yield $line;

               continue;
            }

            // # Plain input
            $chunk = @fread($Stream, 8192);
            if ($chunk !== false && $chunk !== '') {
               $this->buffer .= $chunk;

               continue;
            }
            if (feof($Stream) === false) {
               continue;
            }
            // ?: A terminal without readline: Ctrl-D, or a hangup — what
            //   follows starts on a line of its own, not after the prompt
            if ($terminal) {
               if ($prompted) {
                  $this->Output->write("\n");
               }

               return;
            }

            // ! A last line without a terminator still runs
            if ($this->buffer !== '') {
               $this->buffer .= "\n";
            }
            $Stream = null;
         }
      }
      finally {
         $this->disarm();
      }
   }
   /**
    * Remove the readline callback handler of `prompting()` — a no-op when
    * none is installed. Call it before the process exits or re-execs from
    * inside a prompt — an `exit()` from a signal handler dispatched while the
    * prompt runs, or a `pcntl_exec()`, skips the generator's `finally` — or
    * readline leaves the terminal raw.
    *
    * While installed, readline saves and rewrites the sigaction slots of the
    * signals it handles, beneath PHP's bookkeeping; its restore on removal has
    * left slots pointing at `SIG_ERR` (PHP 8.4: the next delivery SIGSEGVs).
    * Each slot is rewritten from PHP's own handler table (`restart_syscalls`
    * false, as `Process\Signals::install()` registers them).
    */
   public function disarm (): void
   {
      // ?
      if ($this->armed === false) {
         return;
      }

      readline_callback_handler_remove();
      $this->armed = false;

      // ? Without pcntl no PHP handler is installed to rewrite
      if (function_exists('pcntl_signal_get_handler') === false) {
         return;
      }

      // @@ Rewrite every slot readline may have restored
      foreach ([SIGALRM, SIGCONT, SIGHUP, SIGINT, SIGQUIT, SIGTERM, SIGTSTP, SIGWINCH] as $signal) {
         pcntl_signal($signal, pcntl_signal_get_handler($signal), false);
      }
   }

   /** Register the readline completion callback. */
   public function prepare (): void
   {
      // ! A Closure: readline calls it from the scope that reads the keys —
      //   and a completion that fails completes nothing instead of ending the
      //   prompt (and the process reading it)
      readline_completion_function(function (string $search): array {
         try {
            return $this->autocomplete($search);
         }
         catch (Throwable) {
            return [];
         }
      });
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
      if (function_exists('readline_add_history')) {
         readline_add_history($command);
      }

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
               // ! The typed text is the pattern: quote it, never the command
               $pattern = preg_quote($search, '/');
               $found = preg_match("/{$pattern}/i", $command);
               return $found === 1;
            }
         );
      };

      if ($search || count(self::$command) === 0) {
         $found = $filterCommands(static::$commands);
      }
      else if (count(self::$command) === 1) {
         // ? A completed command without subcommands completes nothing more
         $found = $filterCommands(static::$subcommands[self::$command[0]] ?? []);
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

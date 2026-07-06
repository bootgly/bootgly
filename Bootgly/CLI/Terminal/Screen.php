<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use const SIG_DFL;
use const SIGWINCH;
use function exec;
use function function_exists;
use function getenv;
use function is_numeric;
use function pcntl_signal;
use function register_shutdown_function;
use Closure;

use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Data\__String\Escapeable\Viewport\Bufferable;

use Bootgly\CLI\Terminal\Output;


/**
 * Terminal screen buffer control: the alternate screen (full-screen TUIs),
 * the canonical size probe and the resize (SIGWINCH) watcher.
 */
class Screen
{
   use Escapeable;
   use Positionable;
   use Modifiable;
   use Bufferable;


   // * Config
   // ...

   // * Data
   private Output $Output;

   // * Metadata
   /** In the alternate screen buffer? */
   public private(set) bool $alternative;
   /** Shutdown restore registered? */
   private bool $registered;


   public function __construct (Output $Output)
   {
      // * Data
      $this->Output = $Output;

      // * Metadata
      $this->alternative = false;
      $this->registered = false;
   }

   /**
    * Measures the terminal size: COLUMNS / LINES environment first
    * (ncurses convention), then `tput`, then the 80×30 fallback.
    *
    * @return array{0: int, 1: int} The terminal size as [columns, lines].
    */
   public static function measure (): array
   {
      // ! Columns
      $columns = getenv('COLUMNS');
      if (is_numeric($columns) === false && function_exists('exec') === true) {
         $columns = exec('tput cols 2>/dev/null');
      }
      if (is_numeric($columns) === false) {
         $columns = 80;
      }

      // ! Lines
      $lines = getenv('LINES');
      if (is_numeric($lines) === false && function_exists('exec') === true) {
         $lines = exec('tput lines 2>/dev/null');
      }
      if (is_numeric($lines) === false) {
         $lines = 30;
      }

      // :
      return [(int) $columns, (int) $lines];
   }

   /**
    * Enters the alternate screen buffer and clears it.
    * The main screen buffer is restored on `close()` and, as a safety net,
    * on shutdown — covering signal handlers and `exit()` paths.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function open (): self
   {
      // ?
      if ($this->alternative === true) {
         return $this;
      }

      // * Metadata
      $this->alternative = true;

      // @ Switch to the alternate screen buffer
      $this->Output->write(
         self::_START_ESCAPE . self::_VIEWPORT_ENABLE_ALTERNATE_BUFFER
      );
      $this->clear();

      // @ Always restore the main buffer on exit
      if ($this->registered === false) {
         $this->registered = true;

         register_shutdown_function(function (): void {
            $this->close();
         });
      }

      // :
      return $this;
   }

   /**
    * Leaves the alternate screen buffer, restoring the main screen contents.
    * Idempotent: closing an already closed Screen is a no-op.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function close (): self
   {
      // ?
      if ($this->alternative === false) {
         return $this;
      }

      // * Metadata
      $this->alternative = false;

      // @ Switch back to the main screen buffer
      $this->Output->write(
         self::_START_ESCAPE . self::_VIEWPORT_DISABLE_ALTERNATE_BUFFER
      );

      // :
      return $this;
   }

   /**
    * Clears the current screen buffer and homes the cursor.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function clear (): self
   {
      $this->Output->write(
         self::_START_ESCAPE . self::_TEXT_ERASE_IN_DISPLAY_2 .
         self::_START_ESCAPE . self::_CURSOR_POSITION
      );

      // :
      return $this;
   }

   /**
    * Watches terminal resizes (SIGWINCH): each resize measures the screen and
    * forwards the new size to the handler. A null handler restores the default
    * signal behavior.
    *
    * @param null|Closure $handler function (int $columns, int $lines): void
    *
    * @return bool Whether the watcher was installed (or restored).
    */
   public function watch (null|Closure $handler): bool
   {
      // ? Signal handling requires process control
      if (function_exists('pcntl_signal') === false) {
         return false;
      }

      // ?
      if ($handler === null) {
         return pcntl_signal(SIGWINCH, SIG_DFL);
      }

      // :
      return pcntl_signal(SIGWINCH, static function () use ($handler): void {
         [$columns, $lines] = self::measure();

         $handler($columns, $lines);
      });
   }
}

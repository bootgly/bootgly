<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use const SIG_DFL;
use const SIGWINCH;
use function fclose;
use function function_exists;
use function getenv;
use function is_executable;
use function is_resource;
use function is_string;
use function pcntl_signal;
use function preg_match;
use function proc_close;
use function proc_open;
use function register_shutdown_function;
use function stream_get_contents;
use function trim;
use Closure;
use Throwable;

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
   /** Trusted terminal-capability helpers; never resolve executables through PATH. */
   private const array TPUT_BINARIES = [
      '/usr/bin/tput',
      '/bin/tput',
   ];

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
    * Measures the terminal size: validated COLUMNS / LINES environment first,
    * then a trusted absolute `tput` without a shell, then the 80×30 fallback.
    *
    * @return array{0: int, 1: int} The terminal size as [columns, lines].
    */
   public static function measure (): array
   {
      // ! Columns
      $columns = self::parse(getenv('COLUMNS'));
      $columns = $columns === false ? self::probe('cols') : $columns;
      $columns = $columns === false ? 80 : $columns;

      // ! Lines
      $lines = self::parse(getenv('LINES'));
      $lines = $lines === false ? self::probe('lines') : $lines;
      $lines = $lines === false ? 30 : $lines;

      // :
      return [$columns, $lines];
   }

   /** Parse one positive, bounded terminal dimension. */
   private static function parse (false|string $value): false|int
   {
      if (
         $value === false
         || preg_match('/\\A[1-9][0-9]{0,5}\\z/D', $value) !== 1
      ) {
         return false;
      }

      return (int) $value;
   }

   /** Query one capability through a trusted absolute binary without a shell. */
   private static function probe (string $capability): false|int
   {
      if (function_exists('proc_open') === false) {
         return false;
      }

      $binary = null;
      foreach (self::TPUT_BINARIES as $candidate) {
         if (is_executable($candidate)) {
            $binary = $candidate;
            break;
         }
      }
      if ($binary === null) {
         return false;
      }

      $term = getenv('TERM');
      if (
         is_string($term) === false
         || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9+._-]{0,63}\\z/D', $term) !== 1
      ) {
         return false;
      }

      $process = null;
      $pipes = [];

      try {
         $process = @proc_open(
            [$binary, $capability],
            [
               0 => ['file', '/dev/null', 'r'],
               1 => ['pipe', 'w'],
               2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            '/',
            [
               'TERM' => $term,
               'LC_ALL' => 'C',
            ]
         );
         if (is_resource($process) === false) {
            return false;
         }

         $output = stream_get_contents($pipes[1], 32);
         fclose($pipes[1]);
         unset($pipes[1]);

         $status = proc_close($process);
         $process = null;

         if ($status !== 0 || is_string($output) === false) {
            return false;
         }

         return self::parse(trim($output));
      }
      catch (Throwable) {
         return false;
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            proc_close($process);
         }
      }
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

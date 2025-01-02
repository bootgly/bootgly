<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


use function array_values;
use function debug_backtrace;
use function dirname;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Backtrace\Call;


class Backtrace
{
   // * Config
   public static int $options = \DEBUG_BACKTRACE_IGNORE_ARGS;
   public static int $traces = 4;
   public static bool $counter = true;

   // * Data
   /** @var array<int,Call> */
   public array $calls;

   // * Metadata
   // @ Last
   private Call $trace; // @phpstan-ignore-line
   // @ Trace
   public string $dir {
      get {
         return dirname($this->trace->file);
      }
   }
   public string $file {
      get {
         return $this->trace->file;
      }
   }
   public int $line {
      get {
         return $this->trace->line;
      }
   }
   /** @var array<string> */
   public private(set) array $backtraces;


   public function __construct (int $limit = 0)
   {
      // ?
      if ($limit < 0) {
         return;
      }
      // <
      $calls = debug_backtrace(self::$options, $limit);
      unSet($calls[0]);
      $calls = array_values($calls);
      // * Config
      // ...

      // * Data
      $this->calls = array_map(
         fn($call) => new Call($call),
         $calls
      );

      // * Metadata
      // @ Last
      foreach ($this->calls as $call) {
         $this->trace = $call;
         break;
      }
      // @ Trace
      $this->backtraces = [];
   }

   public function dump (): string
   {
      // * Metadata
      $output = '';

      // @
      // TODO use Theme
      $calls = $this->calls;
      if ($calls && $calls[0]->file && $calls[0]->line) {
         $output .= match (\PHP_SAPI) {
            'cli'  => '',
            default => '<small>',
         };

         $n = 1;
         foreach ($calls as $call) {
            $line = $call->line;
            $file = $call->file;

            if ($file && $line) {
               $file = Path::relativize($file, BOOTGLY_WORKING_DIR);

               $trace = '';

               // Trace counter
               if (self::$counter) {
                  $trace .= match (\PHP_SAPI) {
                     'cli' => "\033[93m ",
                     default => ' '
                  };
                  $trace .= $n;
                  $trace .= match (\PHP_SAPI) {
                     'cli' => "\033[0m ",
                     default => ''
                  };
               }
               // Trace file
               $trace .= match (\PHP_SAPI) {
                  'cli' => "\033[0m",
                  default => ''
               };
               $trace .= $file;
               // Trace line
               $trace .= ':';
               $trace .= match (\PHP_SAPI) {
                  'cli' => "\033[96m",
                  default => ''
               };
               $trace .= $line;
               $trace .= match (\PHP_SAPI) {
                  'cli' => "\033[0m",
                  default => ''
               };

               $this->backtraces[] = $trace;
               $output .= $trace;
            }

            if ($n > self::$traces) break;

            $output .= "\n";
            $n++;
         }

         $output .= match (\PHP_SAPI) {
            'cli'  => '',
            default => '</small>',
         };
         $output .= "\n";
      }

      return $output;
   }
}

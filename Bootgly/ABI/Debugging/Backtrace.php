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

use Bootgly\ABI\Data\__String\Path;

class Backtrace
{
   // * Config
   public static int $options = \DEBUG_BACKTRACE_IGNORE_ARGS;
   public static int $traces = 4;
   public static bool $counter = true;

   // * Data
   /** @var array<mixed> */
   public array $calls;

   // * Metadata
   // @ Last
   /** @var array<mixed> */
   private array $trace;
   // @ Trace
   private string $dir;
   private string $file;
   private int $line;
   private array $backtraces;


   public function __construct (int $limit = 0)
   {
      // ?
      if ($limit < 0) {
         return;
      }
      // <
      $calls = \debug_backtrace(self::$options, $limit);
      unSet($calls[0]);
      $calls = \array_values($calls);
      // * Config
      // ...

      // * Data
      $this->calls = $calls;

      // * Metadata
      // @ Last
      foreach ($calls as $call) {
         $this->trace = $call;
         break;
      }
      // @ Trace
      $this->backtraces = [];
   }
   public function __get (string $name): mixed
   {
      // * Metadata
      // @ Last
      switch ($name) {
         case 'dir':
            $this->dir = \dirname($this->trace['file']);
            return $this->dir;
         case 'file':
            $this->file = $this->trace['file'];
            return $this->file;
         case 'line':
            $this->line = $this->trace['line'];
            return $this->line;
         case 'backtraces':
            return $this->backtraces;
         default:
            return $this->trace[$name];
      }
   }

   public function dump (): string
   {
      // * Metadata
      $output = '';

      // @
      // TODO use Theme
      $calls = $this->calls;
      if ($calls && $calls[0]['file'] && $calls[0]['line']) {
         $output .= match (\PHP_SAPI) {
            'cli'  => '',
            default => '<small>',
         };

         $n = 1;
         foreach ($calls as $call) {
            $line = $call['line'] ?? null;
            $file = $call['file'] ?? null;

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

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

   // * Data
   public array $calls;

   // * Meta
   // @ Last
   private array $trace;


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

      // * Meta
      // @ Last
      foreach ($calls as $call) {
         $this->trace = $call; break;
      }
   }
   public function __get (string $name)
   {
      // * Meta
      // @ Last
      switch ($name) {
         case 'dir':
            return \dirname($this->trace['file']);
         default:
            return $this->trace[$name];
      }
   }

   public function dump () : string
   {
      // * Meta
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
         foreach ($calls as $trace) {
            $line = $trace['line'] ?? null;
            $file = $trace['file'] ?? null;

            if ($file && $line) {
               $file = Path::relativize($file, BOOTGLY_WORKING_DIR);

               // Trace count
               $output .= match (\PHP_SAPI) {
                  'cli' => "\033[93m ",
                  default => ' '
               };
               $output .= $n;
               $output .= match (\PHP_SAPI) {
                  'cli' => "\033[0m ",
                  default => ''
               };
               // Trace file
               $output .= $file;
               // Trace line
               $output .= ':';
               $output .= match (\PHP_SAPI) {
                  'cli' => "\033[96m",
                  default => ''
               };
               $output .= $line;
               $output .= match (\PHP_SAPI) {
                  'cli' => "\033[0m",
                  default => ''
               };
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

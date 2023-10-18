<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Throwables;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Data\__String\Tokens\Highlighter;
use Bootgly\ABI\Debugging\Data\Throwables;


abstract class Errors extends Throwables
{
   // * Data
   protected static array $errors = [];


   // @ Error
   public static function collect (int $level, string $message, string $filename, int $line) : bool
   {
      self::$errors[] = [
         'message'  => $message,
         'level'    => $level,
         'filename' => $filename,
         'line'     => $line
      ];

      if ( ! (error_reporting() & $level) ) {
         // This error code is not included in error_reporting, so let it fall
         // through to the standard PHP error handler
         return false;
      }

      throw new \ErrorException($message, 0, $level, $filename, $line);

      return true;
   }

   public static function report (\Throwable $Throwable)
   {
      $Highligher = new Highlighter;

      // * Data
      $class = \get_class($Throwable);
      $code = $Throwable->getCode();
      $message = $Throwable->getMessage();
      // @ file
      $file = $Throwable->getFile();
      $line = $Throwable->getLine();
      $contents = \file_get_contents($file);
      $file = Path::relativize($file, BOOTGLY_WORKING_DIR);

      // @ Output
      $output = "\n";
      // class
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0;37;41m ",
         default => ''
      };
      $output .= $class;
      $output .= match (\PHP_SAPI) {
         'cli' => " \033[0m",
         default => ''
      };
      // code
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[47;30m ",
         default => ''
      };
      $output .= "#";
      $output .= $code;
      $output .= match (\PHP_SAPI) {
         'cli' => " \033[0m\n\n",
         default => ''
      };
      // message
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[97m ",
         default => ''
      };
      $output .= $message;
      $output .= match (\PHP_SAPI) {
         'cli' => " \033[0m\n\n",
         default => ''
      };
      // file
      $output .= " at ";
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[92m",
         default => ''
      };
      $output .= $file;
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0m",
         default => ''
      };
      // file line
      $output .= match (\PHP_SAPI) {
         'cli' => ":\033[96m",
         default => ''
      };
      $output .= $line;
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0m",
         default => ''
      };
      $output .= "\n";
      // file content
      // TODO file content filters
      $output .= $Highligher->highlight($contents, $line);
      $output .= "\n";
      // backtrace
      $backtrace = self::trace($Throwable);
      $traces = count($backtrace);
      $limit = 1; // TODO dynamic with verbosity?

      if ($traces > $limit) {
         $backtrace = array_slice($backtrace, -$limit);

         $output .= match (\PHP_SAPI) {
               'cli' => "\033[90m",
               default => ''
            };
         $output .= '+' . (string) ($traces - $limit) . ' trace calls...';
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[0m",
               default => ''
            };
         $output .= "\n";
      }

      foreach ($backtrace as $trace) {
         // @ trace
         // index
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[93m ",
               default => ''
            };
         $output .= $trace['index'];
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[0m ",
               default => ''
            };
         // file
         $output .= $trace['file'];
         // line
         $output .= ':';
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[96m",
               default => ''
            };
         $output .= $trace['line'];
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[0m",
               default => ''
            };
         // call
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[90m",
               default => ''
            };
         $output .= "\n " . str_repeat(' ', strlen((string) $trace['index']) + 1);
         $output .= $trace['call'];
         $output .= match (\PHP_SAPI) {
               'cli' => "\033[0m",
               default => ''
            };
         $output .= "\n";
      }

      $output .= "\n\n";

      echo $output;
   }

   public static function debug (...$Throwables)
   {
      $Errors = $Throwables ?: self::$errors;

      foreach ($Errors as $Error) {
         self::report($Error);
      }
   }
}

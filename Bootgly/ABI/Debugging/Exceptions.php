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

use Bootgly\ABI\Data\__String\Tokens\Highlighter;
use Bootgly\ABI\Debugging;


abstract class Exceptions implements Debugging
{
   // * Data
   protected static array $exceptions = [];


   public static function collect (\Error|\Exception $E)
   {
      self::$exceptions[] = $E;
   }

   public static function report (\Error|\Exception $E)
   {
      $Highligher = new Highlighter;

      // * Data
      $class = \get_class($E);
      $message = $E->getMessage();
      // @ file
      $file = $E->getFile();
      $line = $E->getLine();
      $contents = \file_get_contents($file);

      // @ Output
      // TODO use Theme
      $output = "\n";
      // class
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0;30;41m ",
         default => ''
      };
      $output .= $class;
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

      $output .= "\n\n";

      echo $output;
   }

   public static function debug (...$Throwables)
   {
      foreach ($Throwables as $E) {
         self::report($E);
      }
   }
}

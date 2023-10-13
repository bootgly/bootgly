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

abstract class Errors
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

   public static function report (\Error $Error)
   {
      $Highligher = new Highlighter;

      // * Data
      $class = \get_class($Error);
      $code = $Error->getCode();
      $message = $Error->getMessage();
      // @ file
      $file = $Error->getFile();
      $line = $Error->getLine();
      $contents = \file_get_contents($file);

      // @ Output
      $output = "\n";
      // class
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0;30;41m ",
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

      $output .= "\n\n";

      echo $output;
   }
}

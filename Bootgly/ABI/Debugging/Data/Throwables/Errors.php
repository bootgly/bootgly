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
use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Data\__String\Tokens\Highlighter;
use Bootgly\ABI\Debugging\Data\Throwables;


abstract class Errors extends Throwables
{
   // * Config
   #public static bool $debug = false;
   #public static bool $print = true;
   #public static bool $return = false;
   #public static bool $exit = true;
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

      switch (\PHP_SAPI) {
         case 'cli':
            $theme = self::DEFAULT_THEME;
            // @ Init options
            $theme['CLI']['options'] = [
               'prepending' => [
                  'type'  => 'callback',
                  'value' => self::wrap(...)
               ],
               'appending' => [
                  'type' => 'string',
                  'value' => self::_RESET_FORMAT
               ]
            ];
            // @ Extend values
            $theme['CLI']['values']['error_code'] = [
               self::_WHITE_BACKGROUND,
               self::_BLACK_FOREGROUND
            ];
            break;
         default:
            $theme = self::DEFAULT_THEME_HTML;
            // TODO
      }

      $Theme = new Theme;
      $Theme->add($theme)->select();

      // @ Output
      $output = "\n";
      // error class name
      $output .= $Theme->apply('class_name', " $class ");
      // error code
      $output .= $Theme->apply('error_code', " #$code ");
      $output .= $Theme->apply('@double_line');
      // message
      $output .= $Theme->apply('message', " $message ");
      $output .= $Theme->apply('@double_line');
      // file
      $output .= " at ";
      $output .=  $Theme->apply('file', $file);
      // file line
      $output .= ':';
      $output .= $Theme->apply('file_line', $line);
      $output .= "\n";
      // file content
      // TODO file content filters
      $output .= $Highligher->highlight($contents, $line);
      $output .= "\n";
      // backtrace
      $backtrace = self::trace($Throwable);
      $traces = count($backtrace);
      $limit = 2; // TODO dynamic with verbosity?

      if ($traces > $limit) {
         $backtrace = array_slice($backtrace, -$limit);

         $output .= $Theme->apply(
            key: 'trace_calls',
            content: '+' . (string) ($traces - $limit) . ' trace calls'
         );

         $output .= "\n";
      }

      foreach ($backtrace as $trace) {
         // @ trace
         // index
         $output .= $Theme->apply('trace_index', " {$trace['index']} ");
         // file
         $output .= $trace['file'];
         // line
         $output .= ':';
         $output .= $Theme->apply('trace_line', $trace['line']);
         // call
         $output .= $Theme->apply(
            key: 'trace_call',
            content: "\n " . str_repeat(' ', strlen((string) $trace['index']) + 1) . $trace['call']
         );

         $output .= "\n";
      }

      $output .= "\n\n";

      echo $output;
   }

   public static function debug (...$Throwables)
   {
      $errors = $Throwables ?: self::$errors;

      foreach ($errors as $Error) {
         if ($Error instanceof \Throwable) {
            self::report($Error);
         }
      }
   }
}

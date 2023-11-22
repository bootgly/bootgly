<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data;


use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Data\__String\Tokens\Highlighter;
use Bootgly\ABI\Debugging;


abstract class Throwables implements Debugging
{
   use Formattable;

   // * Config
   #public static bool $debug = true;
   #public static bool $print = true;
   #public static bool $return = false;
   #public static bool $exit = true;

   // @ Theme
   protected const DEFAULT_THEME = [
      'CLI' => [
         'values' => [
            '@start' => "\n",

            '@double_line' => "\n\n",
            'class_name' => [self::_BLACK_FOREGROUND, self::_RED_BACKGROUND],
            'message' => self::_WHITE_BRIGHT_FOREGROUND,
            'file' => self::_GREEN_BRIGHT_FOREGROUND,
            'file_line' => self::_CYAN_BRIGHT_FOREGROUND,
            'trace_calls' => [self::_BLACK_BRIGHT_FOREGROUND, self::_UNDERLINE_STYLE],
            'trace_index' => self::_YELLOW_BRIGHT_FOREGROUND,
            'trace_file' => '',
            'trace_line' => self::_CYAN_BRIGHT_FOREGROUND,
            'trace_call' => self::_BLACK_BRIGHT_FOREGROUND,

            '@finish' => "\n\n"
         ]
      ]
   ];
   protected const DEFAULT_THEME_HTML = [
      'HTML' => [
         'values' => [
            '@start' => '<pre>',

            '@double_line' => "<br><br>",
            'class_name' => '',
            'message' => '',
            'file' => '',
            'file_line' => '',
            'trace_calls' => '',
            'trace_index' => '',
            'trace_file' => '',
            'trace_line' => '',
            'trace_call' => '',

            '@finish' => '</pre>'
         ]
      ]
   ];

   public static function report (\Throwable $Throwable)
   {
      switch (\PHP_SAPI) {
         case 'cli':
            $theme = Highlighter::DEFAULT_THEME;
            break;
         default:
            $theme = Highlighter::HTML_THEME;
      }
      $Highligher = new Highlighter($theme);

      // * Data
      $class = \get_class($Throwable);
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
            break;
         default:
            $theme = self::DEFAULT_THEME_HTML;
            // TODO
            $theme['HTML']['options'] = [
               'prepending' => [
                  'type'  => 'callback',
                  'value' => function ($value) {
                     return $value;
                  }
               ],
               'appending' => [
                  'type' => 'string',
                  'value' => ''
               ]
            ];
      }
      $Theme = new Theme;
      $Theme->add($theme)->select();

      // @ Output
      $output = $Theme->apply('@start');
      // class name
      $output .= $Theme->apply('class_name', " $class ");
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
            content: '+' . (string) ($traces - $limit) . ' trace calls...'
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

      $output .= $Theme->apply('@finish');

      echo $output;
   }

   public static function trace (\Throwable $Throwable) : array
   {
      $traces = explode("\n", $Throwable->getTraceAsString());
      // @ Reverse array to make steps line up chronologically
      $traces = array_reverse($traces);
      array_shift($traces); // @ Remove {main}
      #array_pop($traces); // @ Remove call to this method
      $length = count($traces);

      $result = [];
      for ($i = 0; $i < $length; $i++) {
         // @ trace
         $index = (string) ($i + 1);
         // @ Replace '#someNum' with '$i', set the right ordering
         $trace = substr($traces[$i], strpos($traces[$i], ' ') + 1);
         // @ Extract file, line, call
         [$file, $call] = explode(": ", $trace);

         $parentesis_position = strrpos($file, '(');

         $line = substr($file, $parentesis_position + 1, -1);
         $file = substr($file, 0, $parentesis_position);
         $file = Path::relativize($file, BOOTGLY_WORKING_DIR);

         $result[] = [
            'index' => $index,
            'file' => $file,
            'line' => $line,
            'call' => $call
         ];
      }

      return $result;
   }

   public static function debug (...$Throwables)
   {
      foreach ($Throwables as $Throwable) {
         self::report($Throwable);
      }
   }
}

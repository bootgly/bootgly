<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data;


use const BOOTGLY_SAPI;
use const BOOTGLY_WORKING_DIR;
use const STR_PAD_LEFT;
use function array_reverse;
use function array_shift;
use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function get_class;
use function htmlspecialchars;
use function max;
use function min;
use function str_pad;
use function str_repeat;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use Closure;
use Throwable;
use WeakMap;

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
   // Terminate with a failure exit status (255) after an uncaught throwable
   public static bool $exit = true;
   #public static bool $output = true;
   #public static bool $return = false;
   public static int $verbosity = 3;
   /**
    * Reporter seam — higher layers push closures here at boot to route
    * throwables into their sinks (log channels, metrics, event buses).
    * @var array<int,Closure(Throwable,array<string,mixed>):void>
    */
   public static array $reporters = [];

   // * Data
   // @ Theme
   protected const DEFAULT_THEME = [
      'CLI' => [
         'values' => [
            '@start' => "\n",

            '@double_break_line' => "\n\n",
            'class_name' => [self::_BLACK_FOREGROUND, self::_RED_BACKGROUND],
            'error_code' => [self::_WHITE_BACKGROUND, self::_BLACK_FOREGROUND],
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
   // Values starting with `<` are literal markup; the others are CSS class names.
   protected const DEFAULT_THEME_HTML = [
      'HTML' => [
         'values' => [
            '@start' => '<pre class="bootgly-throwable">',

            '@double_break_line' => '<br><br>',
            'class_name' => 'class-name',
            'error_code' => 'error-code',
            'message' => 'message',
            'file' => 'file',
            'file_line' => 'file-line',
            'trace_calls' => 'trace-calls',
            'trace_index' => 'trace-index',
            'trace_file' => 'trace-file',
            'trace_line' => 'trace-line',
            'trace_call' => 'trace-call',

            '@finish' => '</pre>'
         ]
      ]
   ];

   // * Metadata
   /** @var WeakMap<Throwable,bool> */
   protected static WeakMap $reported;


   public static function render (Throwable $Throwable, null|int $target = null): string
   {
      // !
      $target ??= BOOTGLY_SAPI === 'cli'
         ? self::TARGET_CLI
         : self::TARGET_HTML;

      // * Data
      $class = get_class($Throwable);
      $code = $Throwable->getCode();
      $message = $Throwable->getMessage();
      // @ file
      $file = $Throwable->getFile();
      $line = $Throwable->getLine();
      // ? Degrade when the source is unreadable — the renderer must never throw
      $contents = @file_get_contents($file);
      $file = Path::relativize($file, BOOTGLY_WORKING_DIR);

      // # Theme
      switch ($target) {
         case self::TARGET_CLI:
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
            // @ Init options
            $theme['HTML']['options'] = [
               'prepending' => [
                  'type'  => 'callback',
                  'value' => static function (string ...$values): string {
                     $value = $values[0] ?? '';
                     // ? Literal markup markers pass through untouched
                     if ($value === '' || $value[0] === '<') {
                        return $value;
                     }
                     // : Styled segment opening
                     return "<span class=\"$value\">";
                  }
               ],
               'appending' => [
                  'type' => 'callback',
                  'value' => static function (string ...$values): string {
                     $value = $values[0] ?? '';
                     if ($value === '' || $value[0] === '<') {
                        return '';
                     }
                     return '</span>';
                  }
               ],
               'escaping' => [
                  'type' => 'callback',
                  'value' => htmlspecialchars(...)
               ]
            ];
      }
      $Theme = new Theme;
      $Theme->add($theme)->select();

      // @ Output
      $output = $Theme->apply('@start');

      // class name
      $output .= $Theme->apply('class_name', " $class ");
      // ? Code chip — only when the throwable carries a meaningful code
      if ($code !== 0) {
         $output .= $Theme->apply('error_code', " #$code ");
      }
      $output .= $Theme->apply('@double_break_line');
      // message
      $output .= $Theme->apply('message', " $message ");

      if (self::$verbosity >= 2) {
         $output .= $Theme->apply('@double_break_line');

         // file
         $output .= " at ";
         $output .=  $Theme->apply('file', $file);
         // file line
         $output .= ':';
         $output .= $Theme->apply('file_line', (string) $line);
         $output .= "\n";
         // file content
         if ($contents !== false) {
            // TODO file content filters
            if ($target === self::TARGET_CLI) {
               $Highlighter = new Highlighter(Highlighter::DEFAULT_THEME);
               $output .= $Highlighter->highlight($contents, $line);
            }
            else {
               $output .= self::excerpt($contents, $line);
            }
            $output .= "\n";
         }
      }

      if (self::$verbosity >= 3) {
         // backtrace
         $backtrace = self::trace($Throwable);
         $traces = count($backtrace);
         $limit = 3; // TODO dynamic with verbosity?

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
            $output .= $target === self::TARGET_HTML
               ? htmlspecialchars($trace['file'])
               : $trace['file'];
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
      }

      $output .= $Theme->apply('@finish');

      // :
      return $output;
   }

   public static function report (Throwable $Throwable): void
   {
      echo self::render($Throwable);
   }

   /**
    * Dispatch a throwable to the registered reporters — once per instance,
    * no matter how many handlers see it (request catch, uncaught, shutdown).
    *
    * @param array<string,mixed> $context
    */
   public static function notify (Throwable $Throwable, array $context = []): void
   {
      // !
      if (isSet(self::$reported) === false) {
         self::$reported = new WeakMap;
      }

      // ? Deduplicate per throwable instance (WeakMap: auto-GC, worker-safe)
      if (isSet(self::$reported[$Throwable])) {
         return;
      }
      self::$reported[$Throwable] = true;

      // @
      foreach (self::$reporters as $Reporter) {
         try {
            $Reporter($Throwable, $context);
         }
         catch (Throwable) {
            // ? A broken reporter must never cascade into the error path
         }
      }
   }

   /**
    * Plain, HTML-escaped source excerpt (±4 lines around the marked line).
    */
   protected static function excerpt (string $contents, int $line): string
   {
      // !
      $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
      $start = max($line - 4, 1);
      $end = min($line + 4, count($lines));
      $width = max(strlen((string) $end), 3);

      // @
      $output = '';
      for ($number = $start; $number <= $end; $number++) {
         $mark = $number === $line ? ' ▶ ' : '   ';
         $padded = str_pad((string) $number, $width, ' ', STR_PAD_LEFT);
         $content = htmlspecialchars($lines[$number - 1] ?? '');

         $output .= $number === $line
            ? "<span class=\"marked-line\">{$mark}{$padded}▕ {$content}</span>\n"
            : "{$mark}{$padded}▕ {$content}\n";
      }

      // :
      return $output;
   }

   /**
    * @param Throwable $Throwable
    * @return array<array<string>>
    */
   public static function trace (Throwable $Throwable): array
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
         if ($parentesis_position === false) {
            $line = '';
            $file = '';
         }
         else {
            $line = substr($file, $parentesis_position + 1, -1);
            $file = substr($file, 0, $parentesis_position);
            $file = Path::relativize($file, BOOTGLY_WORKING_DIR);
         }

         $result[] = [
            'index' => $index,
            'file' => $file,
            'line' => $line,
            'call' => $call
         ];
      }

      return $result;
   }

   public static function debug (mixed ...$Throwables): void
   {
      foreach ($Throwables as $Throwable) {
         if ($Throwable instanceof Throwable === false) {
            continue;
         }

         self::report($Throwable);
      }
   }
}

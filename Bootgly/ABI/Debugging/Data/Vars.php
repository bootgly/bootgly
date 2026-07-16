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
use function count;
use function get_class;
use function get_resource_type;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function str_repeat;
use function strlen;
use function substr;

use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ABI\Debugging\Data\Vars\Dumper;


class Vars implements Debugging
{
   // * Config
   public static bool $debug = false;
   public static bool $print = true;
   public static bool $return = false;
   public static bool $exit = true;
   public const int DEFAULT_IDENTATIONS = 3;
   // _ Validators
   /** @var array<string> */
   public static array $IPs = [];
   // _ Stack
   public static int $traces = 4;
   // _ Identifiers
   public static int $call = 1;
   public static string $title = '';
   /** @var array<string> */
   public static array $labels = [];
   // _ Delimiters
   // Call
   public static null|int $from = null;
   public static null|int $to = null;
   // Title
   public static null|string $search = null;

   // * Data
   // .. Backtrace
   public static null|Backtrace $Backtrace = null;

   // * Metadata
   // ! Templating
   protected static bool $CLI = false;
   // .. Dumper — the CLI value formatter (HTML keeps the legacy walker below)
   private static null|Dumper $Dumper = null;
   // >> Output
   protected static string $Output = '';
   /** @var array<string> */
   protected static array $backtraces = [];
   protected static string $vars = '';


   public static function reset (): void
   {
      // * Config
      // _ Stack
      self::$traces = 4;
      // _ Identifiers
      self::$call = 1;
      self::$title = '';
      self::$labels = [];
      // _ Delimiters
      // Call
      self::$from = null;
      self::$to = null;
      // Title
      self::$search = null;
   }

   /**
    * Dump a value for the HTML target only — the CLI target renders via `Vars\Dumper`.
    */
   private static function dump (
      mixed $value,
      int $indentations = self::DEFAULT_IDENTATIONS
   ): string
   {
      $type = gettype($value);
      switch ($type) {
         case 'boolean':
            // @ header
            // type
            $prefix = "<small>$type</small> ";
            // info
            $info = '';

            // @ value
            // color
            $color = '#75507b';
            // dump
            $dump = match ($value) {
               false => 'FALSE',
               true  => 'TRUE',
               default => null
            };

            break;

         case 'integer':
            // @ header
            // type
            $type = 'int';
            $prefix = "<small>$type</small> ";
            // info
            $info = '';

            // @ value
            // color
            $color = '#4e9a06';
            // dump
            /** @var int */
            $dump = $value;

            break;
         case 'double': // float
            // @ header
            // type
            $type = 'float';
            $prefix = "<small>$type</small> ";
            // info
            $info = '';

            // @ value
            // color
            $color = "#f57900";
            // dump
            /** @var float */
            $dump = $value;

            break;

         case 'string':
            // @ header
            // type
            $type = 'string';
            $prefix = "<small>$type</small> ";
            // info
            /** @var string $value */
            $length = (string) strlen($value);
            $info = ' (length=' . $length . ')';

            // @ value
            // color
            $color = '#cc0000';
            // dump
            $dump = "'" . $value . "'";

            break;

         case 'array':
            // @ header
            // type
            $type = 'array';
            $prefix = "<b>$type</b>";
            // info
            /** @var array<mixed> $value */
            $size = (string) count($value);
            $info = ' (size=' . $size . ") [";

            // @ value
            // color
            $color = '';
            // dump
            $dump = '';
            // * Metadata
            $indentation = str_repeat("\t", $indentations);
            foreach ($value as $_key => $_value) {
               // @@ key
               if (is_string($_key) === true) {
                  $array_key = "'" . $_key . "'";
               } else {
                  $array_key = (string) $_key;
               }
               // @@ value
               if (is_array($_value) === true) {
                  $array_value = '';
                  if (count($_value) > 0) {
                     $array_value .= self::dump($_value, $indentations + self::DEFAULT_IDENTATIONS);
                  } else {
                     $array_value .= '[]';
                  }
               } else {
                  $array_value = self::dump($_value);
               }
               $dump .= "\n" . $indentation . $array_key . ' => ' . $array_value;
            }
            // * Metadata
            $indentation = substr($indentation, self::DEFAULT_IDENTATIONS);
            $dump .= "\n" . $indentation . ']';

            break;

         case 'resource':
            // @ header
            // type
            $type = 'resource';
            $prefix = "<b>$type</b>";
            // info
            /** @var resource $value */
            $info = ' (' . get_resource_type($value) . ')';

            // @ value
            // color
            $color = '';
            // dump
            $dump = '';

            break;

         case 'NULL':
            // @ header
            // type
            $type = '';
            $prefix = '';
            // info
            $info = '';

            // @ value
            // color
            $color = '#3465a4';
            // dump
            $dump = 'NULL';

            break;

         default:
            if (is_object($value) === true) {
               // @ header
               // type
               $type = 'object';
               $prefix = "<b>$type</b>";
               // info
               $info = ' (' . get_class($value) . ')';

               // @ value
               // color
               $color = '';
               // dump
               $dump = '';
            }
            else if (is_callable($value) === true) {
               // @ header
               // type
               $type = 'callable';
               $prefix = "<small>$type</small> ";
               // info
               $info = '';

               // @ value
               // color
               $color = '';
               // dump
               $dump = '';
            }
            else {
               // @ header
               // type
               $type = 'Unknown type';
               $prefix = '';
               // info
               $info = '';

               // @ value
               // color
               $color = 'black';
               // dump
               $dump = '';
            }
      }

      // >
      $output = $prefix . $info . '<span style="color: ' . $color . '">' . $dump . '</span>';

      return $output;
   }

   public static function debug (mixed ...$vars): void
   {
      // ?
      // @ $debug
      if (self::$debug === false) {
         return;
      }
      // @ IPs
      if ( ! empty(self::$IPs) ) {
         foreach (self::$IPs as $IP) {
            $founded = false;
            if ($_SERVER['REMOTE_ADDR'] == $IP || $IP === '*') {
               $founded = true;
               break;
            }
         }
         if ($founded === false) {
            return;
         }
      }

      // * Config
      // _ Identifiers
      $call = self::$call;
      $title = self::$title;
      // _ Delimiters
      // Call
      if (self::$from && (self::$from <=> self::$to) !== -1) {
         self::$from = null;
      }
      $from = self::$from;
      // ---
      if (self::$to === null) {
         $to = $call;
      }
      else {
         $to = self::$to;
      }
      // Title
      if (self::$search === null) {
         $search = self::$title;
      }
      else {
         $search = self::$search;
      }

      // * Data
      // @ Backtrace
      $Backtrace = self::$Backtrace ??= new Backtrace;
      $Backtrace::$traces = self::$traces - 1;
      self::$Backtrace = null;

      // * Metadata
      // ! Templating
      if (BOOTGLY_SAPI === 'cli') {
         self::$CLI = true;
      }
      // >> Output
      self::$Output = '';

      // @
      if ((($from && $call >= $from) || $call >= $to) && $search == $title) {
         self::$Output .= match (self::$CLI) {
            false => '<pre>',
            true  => ''
         };
         // ---
         // @ Title
         if (self::$title) {
            self::$Output .= match (self::$CLI) {
               false => '<b>',
               true  => "\033[93m"
            };
            self::$Output .= self::$title;
            self::$Output .= match (self::$CLI) {
               false => '</b>',
               true  => "\033[0m"
            };
         }
         // ---
         // @ Call
         if (self::$call) {
            self::$Output .= match (self::$CLI) {
               false => '<small>',
               true  => "\n\033[96m"
            };
            self::$Output .= ' in call number: ' . self::$call;
            self::$Output .= match (self::$CLI) {
               false => '</small>',
               true  => "\033[0m"
            };
            self::$Output .= "\n";
         }
         // ---
         // @ Backtraces
         self::$Output .= $Backtrace->dump();
         self::$backtraces = $Backtrace->backtraces;
         // ---
         // @ Vars
         self::$vars = '';
         foreach ($vars as $key => $value) {
            // labels
            if ( ! empty(self::$labels) && @self::$labels[$key]) {
               self::$vars .= match (self::$CLI) {
                  false => '<b style="color:#7d7d7d">',
                  true  => "\033[93m"
               };
               self::$vars .= self::$labels[$key] . "\n";
               self::$vars .= match (self::$CLI) {
                  false => '</b>',
                  true  => "\033[0m"
               };
            }
            // dump
            $dump = match (self::$CLI) {
               true  => (self::$Dumper ??= new Dumper)->dump($value),
               false => self::dump($value)
            };
            self::$vars .= "{$dump}\n";
         }
         // ...
         self::$vars .= match (self::$CLI) {
            false => '</pre><style>pre{-moz-tab-size: 1; tab-size: 1;}</style>',
            true  => ''
         };
         self::$Output .= self::$vars;

         // Print
         if (self::$print) {
            print self::$Output;
         }
         // Exit
         if (self::$exit) {
            if (self::$from == null) {
               exit;
            }
            else if (self::$to == self::$call) {
               exit;
            }
         }
      }

      if (self::$to && self::$search) {
         if ($search === self::$title) {
            self::$call++;
         }
      }
      else {
         self::$call++;
      }
   }

   /**
    * Outputs specific parts or all of it.
    * Use arguments to output specific parts.
    * 
    * @param array<number>|null $backtraces The backtrace stack indexes selected for output
    * @param bool $vars The vars to output
    * 
    * @return string
    */
   public static function output (
      null|array $backtraces = null,
      bool $vars = false
   ): string
   {
      $output = null;

      // backtraces
      if ($backtraces !== null) {
         foreach ($backtraces as $backtrace) {
            $output .= self::$backtraces[(int) $backtrace];
         }
      }
      // vars
      if ($vars) {
         $output .= self::$vars;
      }

      return $output ?? self::$Output;
   }
}

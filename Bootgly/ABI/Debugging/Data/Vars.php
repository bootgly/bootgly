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


use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Backtrace;


class Vars implements Debugging
{
   // * Config
   public static bool $debug = false;
   public static bool $print = true;
   public static bool $return = false;
   public static bool $exit = true;
   public const DEFAULT_IDENTATIONS = 3;
   // _ Validators
   public static array $ips = [];
   // _ Stack
   public static int $traces = 4;
   // _ Identifiers
   public static int $call = 1;
   public static string $title = '';
   public static array $labels = [];
   // _ Delimiters
   // Call
   public static ? int $from = null;
   public static ? int $to = null;
   // Title
   public static ? string $search = null;

   // * Data
   // + Backtrace
   public static ? Backtrace $Backtrace = null;

   // * Meta
   // ! Templating
   protected static bool $CLI = false;
   // >> Output
   protected static string $Output = '';


   public static function reset ()
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

   private static function dump ($value, int $indentations = self::DEFAULT_IDENTATIONS) : string
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
               true  => 'TRUE'
            };
            if (self::$CLI) {
               $dump = "\033[31m" . $dump . "\033[0m";
            }

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
            $dump = $value;
            if (self::$CLI) {
               $dump = "\033[33m" . $dump . "\033[0m";
            }

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
            $dump = $value;
            if (self::$CLI) {
               $dump = "\033[33m" . $dump . "\033[0m";
            }

            break;

         case 'string':
            // @ header
            // type
            $type = 'string';
            $prefix = "<small>$type</small> ";
            // info
            $length = (string) strlen($value);
            $length = match (self::$CLI) {
               false => $length,
               true  => "\033[96m" . $length . "\033[0m"
            };
            $info = ' (length=' . $length . ')';

            // @ value
            // color
            $color = '#cc0000';
            // dump
            $dump = match (self::$CLI) {
               false => "'" . $value . "'",
               true  => "\033[92m'" . $value . "'\033[0m"
            };

            break;

         case 'array':
            // @ header
            // type
            $type = 'array';
            $prefix = "<b>$type</b>";
            // info
            $size = (string) count($value);
            $size = match (self::$CLI) {
               false => $size,
               true => "\033[96m" . $size . "\033[0m"
            };
            $info = ' (size=' . $size . ") [";

            // @ value
            // color
            $color = '';
            // dump
            $dump = '';
            // * Meta
            $indentation = self::$CLI
               ? str_repeat(" ", $indentations)
               : str_repeat("\t", $indentations);
            foreach ($value as $_key => $_value) {
               // @@ key
               if (is_string($_key) === true) {
                  $array_key = match (self::$CLI) {
                     false => "'" . $_key . "'",
                     true => "\033[92m'" . $_key . "'\033[0m"
                  };
               } else {
                  $array_key = match (self::$CLI) {
                     false => (string) $_key,
                     true => "\033[36m" . (string) $_key . "\033[0m"
                  };
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
            // * Meta
            $indentation = substr($indentation, self::DEFAULT_IDENTATIONS);
            $dump .= "\n" . $indentation . ']';

            break;

         case 'resource':
            // @ header
            // type
            $type = 'resource';
            $prefix = "<b>$type</b>";
            // info
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
            if (self::$CLI) {
               $dump = "\033[90m" . $dump . "\033[0m";
            }

            break;

         default:
            if (is_callable($value) === true) {
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
            } else if (is_object($value) === true) {
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
            } else {
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
      if (self::$CLI) { // Console
         $header = match ($type) {
            '' => '',
            default => "\033[95m" . $type . "\033[0m" . $info . ' '
         };

         $output = $header . $dump;
      } else { // HTML
         $output = $prefix . $info . '<span style="color: ' . $color . '">' . $dump . '</span>';
      }

      return $output;
   }

   public static function debug (...$vars)
   {
      // ?
      // @ $debug
      if (self::$debug === false) {
         return;
      }
      // @ IPs
      if ( ! empty(self::$ips) ) {
         foreach (self::$ips as $ip) {
            $founded = false;
            if ($_SERVER['REMOTE_ADDR'] == $ip || $ip === '*') {
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
      } else {
         $to = self::$to;
      }
      // Title
      if (self::$search === null) {
         $search = self::$title;
      } else {
         $search = self::$search;
      }

      // * Data
      // @ Backtrace
      $Backtrace = self::$Backtrace ??= new Backtrace();
      self::$Backtrace = null;

      // * Meta
      // ! Templating
      if (@PHP_SAPI === 'cli') {
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
         // @ Backtrace
         self::$Output .= $Backtrace->dump();
         // ---
         // @ Dump
         foreach ($vars as $key => $value) {
            // labels
            if ( ! empty(self::$labels) && @self::$labels[$key]) {
               self::$Output .= match (self::$CLI) {
                  false => '<b style="color:#7d7d7d">',
                  true  => "\033[93m"
               };
               self::$Output .= self::$labels[$key] . "\n";
               self::$Output .= match (self::$CLI) {
                  false => '</b>',
                  true  => "\033[0m"
               };
            }
            // dump
            self::$Output .= self::dump($value) . "\n";
         }
         // ...
         self::$Output .= "\n";
         self::$Output .= match (self::$CLI) {
            false => '</pre><style>pre{-moz-tab-size: 1; tab-size: 1;}</style>',
            true  => ''
         };

         // Print
         if (self::$print) {
            print self::$Output;
         }
         // Return
         if (self::$return) {
            return self::$Output;
         }
         // Exit
         if (self::$exit) {
            if (self::$from == null) {
               exit;
            } else if (self::$to == self::$call) {
               exit;
            }
         }
      }

      if (self::$to && self::$search) {
         if ($search == self::$title) {
            self::$call++;
         }
      } else {
         self::$call++;
      }
   }
}

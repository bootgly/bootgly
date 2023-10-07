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


use Bootgly\ABI\Debugging;


class Vars implements Debugging
{
   // * Config
   public static bool $debug = false;
   public static bool $print = true;
   public static bool $exit = true;
   public const DEFAULT_IDENTATIONS = 3;
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
   // _ Validators
   public static array $ips;

   // * Data
   public static ? Backtrace $Backtrace = null;

   // * Meta
   // >> Output
   protected static bool $CLI = false;
   protected static string $Output;


   public static function reset ()
   {
      // * Config
      // _ Stack
      self::$traces = 2;
      // _ Identifiers
      self::$call = 1;
      self::$title = null;
      self::$labels = null;
      // _ Delimiters
      // Call
      self::$from = null;
      self::$to = null;
      // Title
      self::$search = null;
   }

   private static function composite ($value, int $indentations = self::DEFAULT_IDENTATIONS) : string
   {
      switch (gettype($value)) {
         case 'boolean':
            $type = 'boolean';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = '#75507b';

            if ($value) {
               $var = 'TRUE';
            } else {
               $var = 'FALSE';
            }

            if (self::$CLI) {
               $var = "\033[31m" . $var . "\033[0m";
            }

            break;

         case 'integer':
            $type = 'int';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = '#4e9a06';

            $var = $value;

            if (self::$CLI) {
               $var = "\033[33m" . $var . "\033[0m";
            }

            break;
         case 'double': // float
            $type = 'float';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = "#f57900";

            $var = $value;

            if (self::$CLI) {
               $var = "\033[33m" . $var . "\033[0m";
            }

            break;

         case 'string':
            $type = 'string';
            $prefix = "<small>$type</small> ";
            // @ info
            $strLen = strlen($value);
            $lengthStr = match (self::$CLI) {
               false => (string) $strLen,
               true  => "\033[96m" . $strLen . "\033[0m"
            };
            $info = ' (length=' . $lengthStr . ')';
            $color = '#cc0000';

            if (!self::$CLI) {
               $var = "'" . $value . "'";
            } else {
               $var = "\033[92m'" . $value . "'\033[0m";
            }

            break;

         case 'array':
            // @ type
            $type = 'array';
            // @ info
            $size = count($value);
            $sizeStr = match (self::$CLI) {
               false => (string) $size,
               true => "\033[96m" . $size . "\033[0m"
            };
            $info = ' (size=' . $sizeStr . ") [";

            // * Meta
            $indentation = self::$CLI ? str_repeat(" ", $indentations) : str_repeat("\t", $indentations);

            $prefix = "<b>$type</b>";
            $color = '';
            $array = $value;

            $var = '';
            foreach ($array as $_key => $_value) {
               // @@ Key
               if (is_string($_key) === true) {
                  $key = match (self::$CLI) {
                     false => "'" . $_key . "'",
                     true => "\033[92m'" . $_key . "'\033[0m"
                  };
               } else {
                  $key = match (self::$CLI) {
                     false => (string) $_key,
                     true => "\033[36m" . (string) $_key . "\033[0m"
                  };
               }

               // @@ Value
               if (is_array($_value) === true) {
                  $value = '';

                  if (count($_value) > 0) {
                     $value .= self::composite($_value, $indentations + self::DEFAULT_IDENTATIONS);
                  } else {
                     $value .= '[]';
                  }
               } else {
                  $value = self::composite($_value);
               }

               $var .= "\n" . $indentation . $key . ' => ' . $value;
            }

            // * Meta
            $indentation = substr($indentation, self::DEFAULT_IDENTATIONS);

            $var .= "\n" . $indentation . ']';

            break;

         case 'resource':
            $type = 'resource';
            $prefix = "<b>$type</b>";
            $info = ' (' . get_resource_type($value) . ')';
            $color = '';
            $var = '';

            break;

         case 'NULL':
            $type = '';
            $prefix = '';
            $info = '';
            $color = '#3465a4';
            $var = 'NULL';

            if (self::$CLI) {
               $var = "\033[90m" . $var . "\033[0m";
            }

            break;

         default:
            if (is_callable($value) === true) {
               $type = 'callable';
               $prefix = "<small>$type</small> ";
               $info = '';
               $color = '';
               $var = '';
            } else if (is_object($value) === true) {
               $type = 'object';
               $prefix = "<b>$type</b>";
               $info = ' (' . get_class($value) . ')';
               $color = '';
               $var = '';
            } else {
               $type = 'Unknown type';
               $prefix = '';
               $info = '';
               $color = 'black';
               $var = '';
            }
      }

      if (!self::$CLI) {
         $dump = $prefix . $info . '<span style="color: ' . $color . '">' . $var . '</span>';
      } else {
         $additional = match ($type) {
            '' => '',
            default => "\033[95m" . $type . "\033[0m" . $info . ' '
         };

         $dump = $additional . $var;
      }

      return $dump;
   }

   private static function boot (array $vars)
   {
      self::$Output = match (self::$CLI) {
         false => '<pre>',
         true  => ''
      };

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

      // @ Backtrace
      $backtrace = self::$Backtrace->backtraces;
      if ($backtrace && $backtrace[0]['file'] && $backtrace[0]['line']) {
         self::$Output .= match (self::$CLI) {
            false => '<small>',
            true  => ''
         };

         $n = 1;
         foreach ($backtrace as $index => $trace) {
            if ($index === 0) {
               continue;
            }

            if (isset($trace['file']) && isset($trace['line'])) {
               self::$Output .= $trace['file'] . ':' . $trace['line'];
            }

            if ($n > self::$traces) {
               break;
            }

            self::$Output .= "\n";

            $n++;
         }

         self::$Output .= match (self::$CLI) {
            false => '</small>',
            true  => ''
         };
         self::$Output .= "\n";
      }

      self::$Output .= "\n";

      // Value
      foreach ($vars as $key => $value) {
         // Labels
         if (@self::$labels[$key]) {
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

         self::$Output .= self::composite($value) . "\n";
      }

      self::$Output .= "\n";
      self::$Output .= match (self::$CLI) {
         false => '</pre><style>pre{-moz-tab-size: 1; tab-size: 1;}</style>',
         true  => ''
      };
   }

   public static function dump (...$vars)
   {
      // ?
      if (self::$debug === false) {
         return;
      }

      if (!empty(self::$ips)) {
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

      // * Data
      // Backtrace
      self::$Backtrace ??= new Backtrace();

      // * Meta
      // Output
      if (@PHP_SAPI === 'cli') {
         self::$CLI = true;
      }

      // Title
      $title = self::$title;
      // Count
      $call = self::$call;

      // To
      if (self::$to === null) {
         $to = self::$call;
      } else {
         $to = self::$to;
      }
      // From
      if (self::$from && (self::$from <=> self::$to) !== -1) {
         self::$from = null;
      }
      $from = self::$from;
      // Search
      if (self::$search === null) {
         $search = self::$title;
      } else {
         $search = self::$search;
      }

      // Catch
      if ((($from && $call >= $from) || $call >= $to) && $search == $title) {
         self::boot($vars);

         // Print
         if (self::$print) {
            print self::$Output;
         }

         self::$Backtrace = null;

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

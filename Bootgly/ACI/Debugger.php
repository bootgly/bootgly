<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


class Debugger
{
   // * Config
   public static bool $debug = false;
   public static bool $print = true;
   public static bool $exit = true;
   // _ Stack
   public static int $traces = 2;
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

   // * Meta
   protected static array $backtrace;
   // >> Output
   protected static bool $CLI = false;
   protected static string $Output;


   public function __construct (...$vars)
   {
      // ?
      if (self::$debug === false) {
         return;
      }

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

      // * Meta
      // Output
      if (@PHP_SAPI === 'cli') {
         self::$CLI = true;
      }

      // @
      self::$backtrace = debug_backtrace();

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
         $this->format($vars);

         // Print
         if (self::$print) {
            print self::$Output;
         }

         self::$backtrace = [];

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

   public static function dump ($value)
   {
      switch (gettype($value)) {
         case 'boolean':
            $type = 'boolean';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = '#75507b';

            if ($value) {
               $var = 'true';
            } else {
               $var = 'false';
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
            $info = ' (length=' . strlen($value) . ')';
            $color = '#cc0000';

            if (! self::$CLI) {
               $var = "'" . $value . "'";
            } else {
               $var = "\033[92m'" . $value . "'\033[0m";
            }

            break;

         case 'array':
            $type = 'array';
            $prefix = "<b>$type</b>";
            $info = ' (size=' . count($value) . ") ";
            $color = '';
            $array = $value;
            $identity = self::$CLI ? "   " : "\t\t\t";

            $var = '';
            foreach ($array as $key => $value) {
               // @@ Key
               if ( is_string($key) ) {
                  if (! self::$CLI) {
                     $key = "'" . $key . "'";
                  } else {
                     $key = "\033[92m'" . $key . "'\033[0m";
                  }
               }

               // @@ Value
               if ( is_array($value) ) {
                  $arrayValueCount = count($value);

                  if (! self::$CLI) {
                     $value = '<b>array</b>';
                  } else {
                     $value = "\033[95marray\033[0m";
                  }

                  $value .= ' (size=' . $arrayValueCount . ") ";

                  if ($arrayValueCount > 0) {
                     $value .= '[...]';
                  } else {
                     $value .= '[]';
                  }
               } else {
                  $value = self::dump($value);
               }

               $var .= "\n" . $identity . $key . ' => ' . $value;
            }

            break;

         case 'object':
            $type = 'object';
            $prefix = "<b>$type</b>";
            $info = ' (' . get_class($value) . ')';
            $color = '';
            $var = '';

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
            $var = 'null';

            if (self::$CLI) {
               $var = "\033[31m" . $var . "\033[0m";
            }

            break;

         default:
            if (is_callable($value)) {
               $type = 'callable';
               $prefix = "<small>$type</small> ";
               $info = '';
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

      if (! self::$CLI) {
         $dump = $prefix . $info . '<span style="color: ' . $color . '">' . $var . '</span>';
      }
      else {
         $dump = "\033[95m".$type."\033[0m" . $info . ' ' . $var;
      }

      return $dump;
   }

   private function format ($vars)
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

      // @ Backtrace
      if (self::$backtrace && self::$backtrace[0]['file'] && self::$backtrace[0]['line']) {
         self::$Output .= match (self::$CLI) {
            false => '<small>',
            true  => ''
         };

         $n = 1;
         foreach (self::$backtrace as $index => $trace) {
            if ($index === 0) {
               continue;
            }

            if (isSet($trace['file']) && isSet($trace['line'])) {
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

         self::$Output .= self::dump($value) . "\n";
      }

      self::$Output .= "\n";
      self::$Output .= match (self::$CLI) {
         false => '</pre><style>pre{-moz-tab-size: 1; tab-size: 1;}</style>',
         true  => ''
      };
   }
}

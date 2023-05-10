<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2017-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


class Debugger // TODO refactor (too old!)
{
   // * Config
   public static $debug = false;
   public static $print = true;
   public static $exit = true;

   public static $cli = false;

   public static $traces = 2;

   // Identifiers
   public static $call = 1; // int
   public static $title; // string
   public static $trace; // array
   public static $vars; // array
   public static $labels; // array

   // Delimiters
   // Call
   public static $from;
   public static $to;
   // Title
   public static $search;
   // Stack
   public static $stacks;
   public static $ips;

   // .Output
   public static $Output;


   // TODO Refactor this function to reduce its Cognitive Complexity from 41 to the 15 allowed.
   public function __construct (...$vars)
   {
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

      // CLI
      if (@PHP_SAPI === 'cli') {
         self::$cli = true;
      }

      // Title
      $title = self::$title;
      // Vars
      if (empty($vars) && self::$vars) {
         $vars = self::$vars;
      }

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
         if (self::$trace !== false && self::$trace === null) {
            $trace = debug_backtrace();
            self::$trace = $trace;
         }

         $this->generate($vars);

         // Print
         if (self::$print) {
            print self::$Output;
         }

         self::$trace = null;

         if (self::$exit) {
            if (self::$from == null) {
               exit;
            } else {
               if (self::$to == self::$call) {
                  exit;
               }
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

   public static function input (...$vars)
   {
      self::$vars = $vars;
   }
   public static function reset ()
   {
      self::$call = 1;
      self::$from = null;
      self::$to = null;
      self::$search = null;
      self::$title = null;
      self::$labels = null;
   }

   // TODO Refactor this function to reduce its Cognitive Complexity from 46 to the 15 allowed.
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

            if (self::$cli) {
               $var = "\033[31m" . $var . "\033[0m";
            }

            break;

         case 'integer':
            $type = 'int';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = '#4e9a06';

            $var = $value;

            if (self::$cli) {
               $var = "\033[33m" . $var . "\033[0m";
            }

            break;
         case 'double': // float
            $type = 'float';
            $prefix = "<small>$type</small> ";
            $info = '';
            $color = "#f57900";

            $var = $value;

            if (self::$cli) {
               $var = "\033[33m" . $var . "\033[0m";
            }

            break;

         case 'string':
            $type = 'string';
            $prefix = "<small>$type</small> ";
            $info = ' (length=' . strlen($value) . ')';
            $color = '#cc0000';

            if (! self::$cli) {
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
            $identity = self::$cli ? "   " : "\t\t\t";

            $var = '';
            foreach ($array as $key => $value) {
               // @@ Key
               if ( is_string($key) ) {
                  if (! self::$cli) {
                     $key = "'" . $key . "'";
                  } else {
                     $key = "\033[92m'" . $key . "'\033[0m";
                  }
               }

               // @@ Value
               if ( is_array($value) ) {
                  $arrayValueCount = count($value);

                  if (! self::$cli) {
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

            if (self::$cli) {
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

      if (! self::$cli) {
         $dump = $prefix . $info . '<span style="color: ' . $color . '">' . $var . '</span>';
      }
      else {
         $dump = "\033[95m".$type."\033[0m" . $info . ' ' . $var;
      }

      return $dump;
   }
   // TODO Refactor this function to reduce its Cognitive Complexity from 36 to the 15 allowed.
   private function generate ($vars)
   {
      self::$Output = "";

      // @ Call
      if (! self::$cli) {
         self::$Output = "<pre>";
      }

      if (self::$title) {
         if (! self::$cli) {
            self::$Output .= '<b>';
         }

         self::$Output .= self::$title;

         if (! self::$cli) {
            self::$Output .= '</b>';
         }
      }

      if (! self::$cli) {
         self::$Output .= '<small>';
      } else {
         self::$Output .= "\n\033[96m";
      }

      self::$Output .= ' in call number: ' . self::$call;

      if (! self::$cli) {
         self::$Output .= '</small>';
      } else {
         self::$Output .= "\033[0m";
      }

      self::$Output .= "\n";

      // @ Trace
      if (self::$trace && self::$trace[0]['file'] && self::$trace[0]['line']) {
         if (! self::$cli) {
            self::$Output .= '<small>';
         }

         $n = 1;
         foreach (self::$trace as $trace) {
            if (isSet($trace['file']) && isSet($trace['line'])) {
               self::$Output .= $trace['file'] . ':' . $trace['line'];
            }

            if ($n > self::$traces) {
               break;
            }

            self::$Output .= "\n";

            $n++;
         }

         if (! self::$cli) {
            self::$Output .= "</small>";
         }

         self::$Output .= "\n";
      }

      self::$Output .= "\n";

      // @ Value
      foreach ($vars as $key => $value) {
         // @ Labels
         if (@self::$labels[$key]) {
            if (! self::$cli) {
               self::$Output .= '<b style="color:#7d7d7d">';
            } else {
               self::$Output .= "\033[93m";
            }

            self::$Output .= self::$labels[$key] . "\n";

            if (! self::$cli) {
               self::$Output .= '</b>';
            } else {
               self::$Output .= "\033[0m";
            }
         }

         self::$Output .= self::dump($value) . "\n";
      }

      self::$Output .= "\n";

      if (! self::$cli) {
         self::$Output .= "</pre>";
         self::$Output .= "<style>pre{-moz-tab-size: 1; tab-size: 1;}</style>";
      }
   }
}

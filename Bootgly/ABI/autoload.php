<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// @ Local namespace
namespace Bootgly\ABI {

   use Bootgly\ABI\Debugging\Data\Throwables\Errors;
   use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;

   use Bootgly\ABI\Debugging\Shutdown;


   // @ Debugging\Data\Errors
   \restore_error_handler();
   \set_error_handler(
      callback: Errors::collect(...),
      error_levels: E_ALL | E_STRICT
   );

   // @ Debugging\Data\Exceptions
   \restore_exception_handler();
   \set_exception_handler(
      callback: Exceptions::collect(...) // @phpstan-ignore-line
   );

   // @ Debugging\Shutdown
   \register_shutdown_function(
      callback: Shutdown::debug(...)
   );

   // @ IO\FS
   // functions
   if (\function_exists('\Bootgly\ABI\copy_recursively') === false) {
      function copy_recursively (string $source, string $destination): void
      {
         if (\is_dir($source) === true) {
            \mkdir($destination);

            $paths = \scandir($source);
            if ($paths === false) {
               return;
            }

            foreach ($paths as $path) {
               if ($path !== '.' && $path !== '..') {
                  copy_recursively("$source/$path", "$destination/$path");
               }
            }
         }
         else if (\file_exists($source) === true) {
            \copy($source, $destination);
         }
      }
   }
}

// @ Global namespace
namespace {

   use Bootgly\ABI\Data\__String;
   use Bootgly\ABI\Debugging\Backtrace;
   use Bootgly\ABI\Debugging\Data\Vars;

   // @ Debugging\Data\Vars
   // functions
   if (\function_exists('dump') === false) {
      function dump (mixed ...$vars): void
      {
         // * Data
         // + Backtrace
         Vars::$Backtrace = new Backtrace;

         Vars::debug(...$vars);
      }
   }
   if (\function_exists('dd') === false) { // dd = dump and die
      function dd (mixed ...$vars): void
      {
         // * Config
         Vars::$exit = true;
         Vars::$debug = true;
         // * Data
         // + Backtrace
         Vars::$Backtrace = new Backtrace;

         Vars::debug(...$vars);
      }
   }

   /**
    * Polyfills mb_* functions (used in Bootgly only)
    *
    * @author Fabien Potencier and Symfony contributors
    * @link https://github.com/symfony/polyfill-mbstring
    */
   // mb_strlen
   if (!\function_exists('mb_strlen')) {
      function mb_strlen(string $string, string|null $encoding = 'UTF-8'): int|false
      {
         $encoding = __String::encoding($encoding);

         if ($encoding === 'CP850' || $encoding === 'ASCII') {
            return \strlen($string);
         }

         return @\iconv_strlen($string, $encoding);
      }
   }

   // mb_convert_case
   if (!\function_exists('mb_convert_case')) {
      function mb_convert_case(string $string, int $mode, string|null $encoding = 'UTF-8'): string|false
      {
         if ($string === '') {
            return '';
         }

         $encoding = __String::encoding($encoding);

         if ($encoding === 'UTF-8') {
            $encoding = null;
            if (!\preg_match('//u', $string)) {
               $string = @\iconv('UTF-8', 'UTF-8//IGNORE', $string);
            }
         }
         else {
            $string = \iconv($encoding, 'UTF-8//IGNORE', $string);
         }

         if ($string === false) {
            return false;
         }

         if ($mode === \MB_CASE_TITLE) {
            static $titleRegexp = null;

            if ($titleRegexp === null) {
               $titleRegexp = __String::mapping('titleCaseRegexp');
            }

            $string = \preg_replace_callback($titleRegexp, function (array $string): string {
               $uppercase = \mb_convert_case($string[1], \MB_CASE_UPPER, 'UTF-8');
               $lowercase = \mb_convert_case($string[2], \MB_CASE_LOWER, 'UTF-8');

               return "{$uppercase}{$lowercase}";
            }, (string) $string);
         }
         else {
            if ($mode === \MB_CASE_UPPER) {
               static $upper = null;
               if ($upper === null) {
                  $upper = __String::mapping('upperCase');
               }
               $map = $upper;
            }
            else {
               if (\MB_CASE_FOLD === $mode) {
                  static $caseFolding = null;
                  if ($caseFolding === null) {
                     $caseFolding = __String::mapping('caseFolding');
                  }
                  $string = \strtr($string, $caseFolding);
               }

               static $lower = null;
               if ($lower === null) {
                  $lower = __String::mapping('lowerCase');
               }
               $map = $lower;
            }

            static $ulenMask = ["\xC0" => 2, "\xD0" => 2, "\xE0" => 3, "\xF0" => 4];

            $i = 0;
            $len = \strlen($string);

            while ($i < $len) {
               $ulen = $string[$i] < "\x80" ? 1 : $ulenMask[$string[$i] & "\xF0"];
               $uchr = \substr($string, $i, $ulen);
               $i += $ulen;

               if (isSet($map[$uchr])) {
                  $uchr = $map[$uchr];
                  $nlen = \strlen($uchr);

                  if ($nlen === $ulen) {
                     $nlen = $i;

                     do {
                        $string[--$nlen] = $uchr[--$ulen];
                     }
                     while ($ulen);
                  }
                  else {
                     $string = \substr_replace($string, $uchr, $i - $ulen, $ulen);
                     $len += $nlen - $ulen;
                     $i += $nlen - $ulen;
                  }
               }
            }
         }

         if ($encoding === null) {
            return $string;
         }

         return \iconv('UTF-8', $encoding . '//IGNORE', $string);
      }
   }

   // mb_strtoupper
   if (!function_exists('mb_strtoupper')) {
      function mb_strtoupper(string $string, string|null $encoding = 'UTF-8'): string
      {
         return mb_convert_case($string, \MB_CASE_UPPER, $encoding);
      }
   }

   // mb_strtolower
   if (!function_exists('mb_strtolower')) {
      function mb_strtolower(string $string, string|null $encoding = 'UTF-8'): string
      {
         return mb_convert_case($string, \MB_CASE_LOWER, $encoding);
      }
   }

   // mb_substr
   if (!function_exists('mb_substr')) {
      function mb_substr(string $string, int $start, int|null $length = null, string|null $encoding = 'UTF-8'): string
      {
         $encoding = __String::encoding($encoding);
         if ('CP850' === $encoding || 'ASCII' === $encoding) {
            return (string) \substr($string, $start, null === $length ? 2147483647 : $length);
         }
 
         if ($start < 0) {
            $start = \iconv_strlen($string, $encoding) + $start;

            if ($start < 0) {
               $start = 0;
            }
         }
 
         if ($length === null) {
            $length = 2147483647;
         }
         elseif ($length < 0) {
            $length = \iconv_strlen($string, $encoding) + $length - $start;

            if ($length < 0) {
               return '';
            }
         }
 
         return (string) \iconv_substr($string, $start, $length, $encoding);
      }
   }
}

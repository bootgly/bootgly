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
      error_levels: E_ALL
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
         else if (\is_file($source) === true) {
            // regular files only — sockets, FIFOs and devices are not copyable
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

         // ? Runtimes without ext-iconv (e.g. WASM builds): count code points with PCRE
         if (!\function_exists('iconv_strlen')) {
            if ($encoding === 'UTF-8' && \preg_match_all('/./su', $string, $matches) !== false) {
               return \count($matches[0]);
            }

            return \strlen($string);
         }

         return @\iconv_strlen($string, $encoding);
      }
   }

   // mb_convert_case
   if (!\function_exists('mb_convert_case')) {
      function mb_convert_case(string $string, int $mode, string|null $encoding = 'UTF-8'): string
      {
         if ($string === '') {
            return '';
         }

         $encoding = __String::encoding($encoding);

         if ($encoding === 'UTF-8') {
            $encoding = null;
            if (!\preg_match('//u', $string)) {
               $string = \function_exists('iconv')
                  ? @\iconv('UTF-8', 'UTF-8//IGNORE', $string)
                  : false;
            }
         }
         else {
            // ? Non-UTF-8 conversion requires ext-iconv
            $string = \function_exists('iconv')
               ? \iconv($encoding, 'UTF-8//IGNORE', $string)
               : false;
         }

         if ($string === false) {
            return '';
         }

         if ($mode === 2) {
            static $titleRegexp = null;

            if ($titleRegexp === null) {
               $titleRegexp = __String::mapping('titleCaseRegexp');
            }

            $string = \preg_replace_callback($titleRegexp, function (array $string): string {
               $uppercase = \mb_convert_case($string[1], 0, 'UTF-8');
               $lowercase = \mb_convert_case($string[2], 1, 'UTF-8');

               return "{$uppercase}{$lowercase}";
            }, (string) $string);
         }
         else {
            if ($mode === 0) {
               static $upper = null;
               if ($upper === null) {
                  $upper = __String::mapping('upperCase');
               }
               $map = $upper;
            }
            else {
               if ($mode === 3) {
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

         if (!is_string($string)) {
            return '';
         }

         if ($encoding === null) {
            return $string;
         }

         // @ Encode (requires ext-iconv; UTF-8 targets return above)
         if (!\function_exists('iconv')) {
            return $string;
         }

         return \iconv(
            'UTF-8',
            "{$encoding}//IGNORE",
            $string
         ) ?: '';
      }
   }

   // mb_strtoupper
   if (!function_exists('mb_strtoupper')) {
      function mb_strtoupper(string $string, string|null $encoding = 'UTF-8'): string
      {
         return mb_convert_case($string, 0, $encoding);
      }
   }

   // mb_strtolower
   if (!function_exists('mb_strtolower')) {
      function mb_strtolower(string $string, string|null $encoding = 'UTF-8'): string
      {
         return mb_convert_case($string, 1, $encoding);
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

         // ? Runtimes without ext-iconv (e.g. WASM builds): slice code points with PCRE
         if (!\function_exists('iconv_substr')) {
            if ($encoding === 'UTF-8' && \preg_match_all('/./su', $string, $matches) !== false) {
               $sliced = \array_slice($matches[0], $start, $length);

               return \implode('', $sliced);
            }

            return (string) \substr($string, $start, $length ?? 2147483647);
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

   // mb_str_split
   if (!function_exists('mb_str_split')) {
      /**
       * @return array<int,string>
       */
      function mb_str_split(string $string, int $length = 1, string|null $encoding = 'UTF-8'): array
      {
         // ? Split into code points with PCRE (UTF-8)
         $chars = \preg_split('//u', $string, -1, \PREG_SPLIT_NO_EMPTY);
         if ($chars === false) {
            $chars = \str_split($string);
         }

         if ($length <= 1) {
            return $chars;
         }

         return \array_map('implode', \array_chunk($chars, $length));
      }
   }

   // mb_chr
   if (!function_exists('mb_chr')) {
      function mb_chr(int $codepoint, string|null $encoding = 'UTF-8'): string|false
      {
         // ? UTF-8 encode by codepoint range (1–4 bytes)
         if ($codepoint < 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            return false;
         }
         if ($codepoint < 0x80) {
            return \chr($codepoint);
         }
         if ($codepoint < 0x800) {
            return \chr(0xC0 | ($codepoint >> 6)) . \chr(0x80 | ($codepoint & 0x3F));
         }
         if ($codepoint < 0x10000) {
            return \chr(0xE0 | ($codepoint >> 12))
               . \chr(0x80 | (($codepoint >> 6) & 0x3F))
               . \chr(0x80 | ($codepoint & 0x3F));
         }

         return \chr(0xF0 | ($codepoint >> 18))
            . \chr(0x80 | (($codepoint >> 12) & 0x3F))
            . \chr(0x80 | (($codepoint >> 6) & 0x3F))
            . \chr(0x80 | ($codepoint & 0x3F));
      }
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


use const BOOTGLY_WORKING_DIR;
use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function get_class;
use function get_debug_type;
use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function ob_get_clean;
use function ob_start;
use function str_replace;
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Backtrace\Call;


/**
 * Built-in, self-contained debug page (development error page).
 *
 * Stateless: everything is computed per call, so it is safe inside
 * long-running workers. All values are HTML-escaped during preparation —
 * the page template only echoes.
 */
final class Page
{
   // * Data
   private const string TEMPLATE = __DIR__ . '/pages/debug.page.php';
   // # Bounds (self-protection against pathological throwables)
   private const int CHAIN_LIMIT = 5;
   private const int FRAMES_LIMIT = 32;
   private const int ARG_LENGTH_LIMIT = 80;


   /**
    * Render the debug page for a throwable as a full HTML document.
    *
    * @param array<string,mixed> $context Extra sections (e.g. request data) injected by the caller.
    */
   public static function render (Throwable $Throwable, array $context = []): string
   {
      // !
      $chain = [];
      $Current = $Throwable;
      while ($Current !== null && count($chain) < self::CHAIN_LIMIT) {
         $chain[] = self::section($Current);
         $Current = $Current->getPrevious();
      }

      $page = [
         'title' => htmlspecialchars(get_class($Throwable)),
         'chain' => $chain,
         'context' => self::contextualize($context)
      ];

      // @ Assemble via the raw page resource (opcache-cached include)
      ob_start();
      include self::TEMPLATE;

      // :
      return (string) ob_get_clean();
   }

   /**
    * Prepare one throwable of the chain: header data + navigable frames.
    *
    * @return array<string,mixed>
    */
   private static function section (Throwable $Throwable): array
   {
      // ! Frame 0 = the throw location itself; getTrace() holds the callers
      $calls = [
         new Call([
            'file' => $Throwable->getFile(),
            'line' => $Throwable->getLine(),
            'function' => '{throw}'
         ])
      ];
      foreach ($Throwable->getTrace() as $trace) {
         $calls[] = new Call($trace);
      }

      // @
      $total = count($calls);
      $calls = array_slice($calls, 0, self::FRAMES_LIMIT);

      $frames = [];
      foreach ($calls as $Call) {
         $signature = $Call->function;
         if ($Call->class !== null) {
            $signature = "{$Call->class}{$Call->type}{$Call->function}";
         }

         $args = [];
         foreach ($Call->args ?? [] as $arg) {
            $args[] = htmlspecialchars(self::preview($arg));
         }

         $frames[] = [
            'signature' => htmlspecialchars($signature),
            'file' => htmlspecialchars(
               $Call->file !== null
                  ? Path::relativize($Call->file, BOOTGLY_WORKING_DIR)
                  : '[internal]'
            ),
            'line' => $Call->line ?? 0,
            'excerpt' => $Call->file !== null && $Call->line !== null
               ? self::excerpt($Call->file, $Call->line)
               : [],
            'args' => $args
         ];
      }

      $code = $Throwable->getCode();

      // :
      return [
         'class' => htmlspecialchars(get_class($Throwable)),
         'code' => $code !== 0 ? htmlspecialchars("#$code") : '',
         'message' => htmlspecialchars($Throwable->getMessage()),
         'frames' => $frames,
         'overflow' => max($total - self::FRAMES_LIMIT, 0)
      ];
   }

   /**
    * Escaped source excerpt (±8 lines) around a marked line.
    *
    * @return array<int,array{number:int,content:string,marked:bool}>
    */
   private static function excerpt (string $file, int $line): array
   {
      // ? Degrade silently — the debug page must never throw
      $contents = @file_get_contents($file);
      if ($contents === false) {
         return [];
      }

      // @
      $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
      $start = max($line - 8, 1);
      $end = min($line + 8, count($lines));

      $excerpt = [];
      for ($number = $start; $number <= $end; $number++) {
         $excerpt[] = [
            'number' => $number,
            'content' => htmlspecialchars($lines[$number - 1] ?? ''),
            'marked' => $number === $line
         ];
      }

      // :
      return $excerpt;
   }

   /**
    * Bounded, depth-1 preview of a value (frame args, context values).
    */
   private static function preview (mixed $value): string
   {
      // ?:
      if (is_string($value)) {
         $capped = mb_strlen($value) > self::ARG_LENGTH_LIMIT
            ? mb_substr($value, 0, self::ARG_LENGTH_LIMIT) . '…'
            : $value;

         return "'$capped'";
      }
      if (is_bool($value)) {
         return $value ? 'true' : 'false';
      }
      if ($value === null) {
         return 'null';
      }
      if (is_array($value)) {
         return 'array(' . count($value) . ')';
      }
      if (is_object($value)) {
         return get_class($value);
      }
      if (is_scalar($value)) {
         return (string) $value;
      }

      // :
      return get_debug_type($value);
   }

   /**
    * Escape context sections into printable key/value rows (depth 1).
    *
    * @param array<string,mixed> $context
    * @return array<string,array<string,string>>
    */
   private static function contextualize (array $context): array
   {
      $sections = [];

      foreach ($context as $section => $values) {
         $rows = [];

         if (is_array($values)) {
            foreach ($values as $key => $value) {
               $rows[htmlspecialchars((string) $key)] = htmlspecialchars(self::preview($value));
            }
         }
         else {
            $rows[''] = htmlspecialchars(self::preview($values));
         }

         $sections[htmlspecialchars((string) $section)] = $rows;
      }

      // :
      return $sections;
   }
}

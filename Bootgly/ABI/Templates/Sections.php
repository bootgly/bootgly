<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use function array_key_last;
use function array_pop;
use function count;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;


/**
 * Render frame/section stack used by the compiled inheritance and
 * composition directives (@extends, @section, @yield, @component, @slot).
 *
 * Defensive by design: every operation is a safe no-op without a frame —
 * failures inside compiled code are mapped by Template::render(), never here.
 */
class Sections
{
   // * Data
   /**
    * Render frames: one per root render, one more per open component.
    * @var array<int,array{sections:array<string,string>,captures:array<int,string>,component:null|string,variables:array<string,mixed>,parent:null|array{string,null|string,null|int},buffered:bool}>
    */
   private static array $frames = [];

   // * Metadata
   public static int $depth = 0;


   /**
    * Open a render frame (a component frame buffers its default slot).
    *
    * @param array<string,mixed> $variables
    *
    * @return int The new frame depth.
    */
   public static function open (null|string $component = null, array $variables = []): int
   {
      // !
      $buffered = false;
      if ($component !== null) {
         $buffered = ob_start() !== false;
      }

      // @
      self::$frames[] = [
         'sections' => [],
         'captures' => [],
         'component' => $component,
         'variables' => $variables,
         'parent' => null,
         'buffered' => $buffered
      ];

      // :
      return self::$depth = count(self::$frames);
   }
   /**
    * Close the current frame, discarding unfinished captures and buffers.
    */
   public static function close (): void
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return;
      }

      // @
      $frame = self::$frames[$index];
      // Discard unfinished section captures
      $captures = count($frame['captures']);
      while ($captures-- > 0) {
         @ob_end_clean();
      }
      // Discard a pending component buffer
      if ($frame['buffered']) {
         @ob_end_clean();
      }

      array_pop(self::$frames);
      self::$depth = count(self::$frames);
   }

   /**
    * Record the parent template of the current frame (@extends).
    *
    * Compiled directives pass their origin (__FILE__ = compiled cache,
    * __LINE__ = template line, thanks to line parity) so resolution
    * failures can point back at the @extends source line.
    */
   public static function extend (string $template, null|string $origin = null, null|int $line = null): void
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return;
      }

      // @
      self::$frames[$index]['parent'] = [$template, $origin, $line];
   }
   /**
    * Consume the parent template recorded by extend(), if any.
    *
    * @return null|array{string,null|string,null|int} [template, compiled origin, line]
    */
   public static function pull (): null|array
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return null;
      }

      // @
      $parent = self::$frames[$index]['parent'];
      self::$frames[$index]['parent'] = null;

      // :
      return $parent;
   }

   /**
    * Start capturing a section (@section name: / @slot name:).
    */
   public static function start (string $section): void
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return;
      }

      // @
      if (ob_start() !== false) {
         self::$frames[$index]['captures'][] = $section;
      }
   }
   /**
    * End the current capture — first writer wins (child runs before parent).
    */
   public static function end (): void
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null || self::$frames[$index]['captures'] === []) {
         return;
      }

      // @
      $section = array_pop(self::$frames[$index]['captures']);
      $content = (string) ob_get_clean();

      self::$frames[$index]['sections'][$section] ??= $content;
   }

   /**
    * Seal the current component frame: capture its default slot and hand
    * back what Template::compose() needs to render the component template.
    *
    * @return array{null|string,array<string,mixed>}
    */
   public static function seal (): array
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return [null, []];
      }

      // @
      $frame = &self::$frames[$index];
      if ($frame['buffered']) {
         $frame['sections']['slot'] ??= (string) ob_get_clean();
         $frame['buffered'] = false;
      }

      // :
      return [$frame['component'], $frame['variables']];
   }

   public static function check (string $section): bool
   {
      $index = array_key_last(self::$frames);

      return $index !== null && isSet(self::$frames[$index]['sections'][$section]);
   }
   public static function fetch (string $section): string
   {
      // ?
      $index = array_key_last(self::$frames);
      if ($index === null) {
         return '';
      }

      // :
      return self::$frames[$index]['sections'][$section] ?? '';
   }
}

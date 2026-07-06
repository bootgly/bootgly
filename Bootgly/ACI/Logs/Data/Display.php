<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Data;


class Display
{
   // * Config
   // @ Segments the Line formatter prints, as bitmask flags combined with show().
   //   `message` is the only content segment; the others annotate it around the line.
   public const int NONE      = 0;       // print nothing (the local stdout sink stays silent)
   public const int MESSAGE   = 1 << 0;  // the message text
   public const int TIMESTAMP = 1 << 1;  // [ISO-8601 timestamp]
   public const int CHANNEL   = 1 << 2;  // channel name
   public const int SEVERITY  = 1 << 3;  // level label (EMERGENCY … DEBUG)
   public const int CONTEXT   = 1 << 4;  // inline context dump

   // @ Active segment mask, read globally by the Line formatter. Mutate via show().
   public static int $segments = self::MESSAGE;


   /**
    * Choose which segments the Line formatter prints, replacing the current selection.
    *
    * Combine flags freely: `Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL)`.
    * Called with no arguments (or `Display::NONE`) it silences the local stdout output.
    *
    * @param int ...$segments Segment flags to enable (`Display::*`).
    */
   public static function show (int ...$segments): void
   {
      $mask = self::NONE;
      foreach ($segments as $segment) {
         $mask |= $segment;
      }

      self::$segments = $mask;
   }
}

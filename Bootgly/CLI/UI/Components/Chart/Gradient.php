<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Chart;


use function count;
use function getenv;
use function hexdec;
use function intdiv;
use function round;
use function str_contains;
use function strlen;
use function substr;
use InvalidArgumentException;


/**
 * Color gradient — hex stops interpolated into 101 SGR foreground escapes (0-100%).
 * Truecolor (38;2;R;G;B) when the terminal advertises it (COLORTERM), otherwise the
 * 256-color cube (38;5;N). Samples carry no reset — consumers reset at frame end.
 */
class Gradient
{
   // * Config
   /** @var array<int,string> Hex stops (`#RRGGBB`) — 1 = solid, 2 = linear, 3 = start/mid/end */
   public private(set) array $stops;
   /** 256-color mode (38;5;N) instead of truecolor (38;2;R;G;B) */
   public private(set) bool $extended;

   // * Metadata
   /** @var array<int,string> Lazy escape cache, indexed 0-100 */
   private array $steps;


   /**
    * @param array<int,string> $stops 1 to 3 hex colors (`#RRGGBB`).
    * @param null|bool $extended Force the 256-color cube — `null` detects via `COLORTERM`.
    */
   public function __construct (array $stops, null|bool $extended = null)
   {
      // ?
      if (count($stops) < 1 || count($stops) > 3) {
         throw new InvalidArgumentException('Gradient expects 1 to 3 hex stops.');
      }

      // * Config
      $this->stops = $stops;
      $this->extended = $extended ?? $this->detect();
   }


   /**
    * Samples the gradient escape at a percentage.
    *
    * @param int $percent The position (clamped to 0-100).
    *
    * @return string The SGR foreground escape.
    */
   public function sample (int $percent): string
   {
      // ! Lazy 101-step build
      if (isSet($this->steps) === false) {
         $this->build();
      }

      // ? Clamp
      if ($percent < 0) {
         $percent = 0;
      }
      else if ($percent > 100) {
         $percent = 100;
      }

      // :
      return $this->steps[$percent];
   }

   /**
    * Detects 256-color mode — truecolor terminals advertise via `COLORTERM`.
    */
   private function detect (): bool
   {
      $advertised = (string) getenv('COLORTERM');

      return ! (str_contains($advertised, 'truecolor') || str_contains($advertised, '24bit'));
   }

   /**
    * Builds the 101 escapes interpolating the stops linearly
    * (3 stops split the range in two passes: 0-50 and 50-100).
    */
   private function build (): void
   {
      // !
      /** @var array<int,array<int,int>> $channels */
      $channels = [];
      foreach ($this->stops as $stop) {
         $channels[] = $this->decode($stop);
      }

      $last = count($channels) - 1;

      // @@
      for ($percent = 0; $percent <= 100; $percent++) {
         // ? Solid color
         if ($last === 0) {
            [$red, $green, $blue] = $channels[0];
         }
         else {
            // ! Segment (2 stops = single 0-100 pass; 3 stops = two passes of 50)
            $segments = $last;
            $position = $percent * $segments / 100;
            $segment = (int) $position;
            if ($segment === $segments) {
               $segment--;
            }
            $offset = $position - $segment;

            [$red1, $green1, $blue1] = $channels[$segment];
            [$red2, $green2, $blue2] = $channels[$segment + 1];

            $red = (int) round($red1 + ($red2 - $red1) * $offset);
            $green = (int) round($green1 + ($green2 - $green1) * $offset);
            $blue = (int) round($blue1 + ($blue2 - $blue1) * $offset);
         }

         $this->steps[$percent] = $this->escape($red, $green, $blue);
      }
   }

   /**
    * Decodes a `#RRGGBB` hex color into RGB channels.
    *
    * @return array<int,int> `[red, green, blue]`
    */
   private function decode (string $hex): array
   {
      // ?
      if (strlen($hex) !== 7 || $hex[0] !== '#') {
         throw new InvalidArgumentException("Invalid hex color: `{$hex}` — expected `#RRGGBB`.");
      }

      // :
      return [
         (int) hexdec(substr($hex, 1, 2)),
         (int) hexdec(substr($hex, 3, 2)),
         (int) hexdec(substr($hex, 5, 2))
      ];
   }

   /**
    * Escapes an RGB color — truecolor, or the nearest 256-color cube entry.
    */
   private function escape (int $red, int $green, int $blue): string
   {
      // ?: 256-color cube (6×6×6 levels, offset 16)
      if ($this->extended === true) {
         $cube = 16
            + 36 * (int) round($red * 5 / 255)
            + 6 * (int) round($green * 5 / 255)
            + (int) round($blue * 5 / 255);

         return "\e[38;5;{$cube}m";
      }

      // :
      return "\e[38;2;{$red};{$green};{$blue}m";
   }
}

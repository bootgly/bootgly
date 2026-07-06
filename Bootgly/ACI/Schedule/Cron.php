<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


use function count;
use function date;
use function explode;
use function max;
use function preg_split;
use function str_contains;
use function trim;
use InvalidArgumentException;


/**
 * Native standard 5-field cron expression (`m h dom mon dow`).
 *
 * Supports `*`, lists (`,`), ranges (`a-b`), and steps (`* / n`, `a-b/n`).
 * Day-of-week accepts both 0 and 7 for Sunday.
 */
final class Cron
{
   // * Config
   /**
    * The raw cron expression.
    */
   public private(set) string $expression;

   // * Metadata
   /**
    * Search horizon (years) for advance() to bound the minute scan.
    */
   private const int MAX_YEARS = 4;

   /** @var array<int,true> */
   private array $minutes;
   /** @var array<int,true> */
   private array $hours;
   /** @var array<int,true> */
   private array $days;
   /** @var array<int,true> */
   private array $months;
   /** @var array<int,true> */
   private array $weekdays;
   /**
    * Whether the day-of-month field is unrestricted (`*`).
    */
   private bool $domWild;
   /**
    * Whether the day-of-week field is unrestricted (`*`).
    */
   private bool $dowWild;


   public function __construct (string $expression)
   {
      // * Config
      $this->expression = $expression;

      // @
      $this->parse();
   }

   /**
    * Whether the expression matches the minute of the given timestamp.
    */
   public function check (int $timestamp): bool
   {
      $minute  = (int) date('i', $timestamp);
      $hour    = (int) date('G', $timestamp);
      $day     = (int) date('j', $timestamp);
      $month   = (int) date('n', $timestamp);
      $weekday = (int) date('w', $timestamp);

      // ? Minute, hour and month must always match
      if (
         isSet($this->minutes[$minute]) === false ||
         isSet($this->hours[$hour]) === false ||
         isSet($this->months[$month]) === false
      ) {
         return false;
      }

      $domMatch = isSet($this->days[$day]);
      $dowMatch = isSet($this->weekdays[$weekday]);

      // ? Vixie cron: when both day-of-month and day-of-week are restricted,
      // ? the entry runs when EITHER field matches.
      if ($this->domWild === false && $this->dowWild === false) {
         // :?
         return $domMatch || $dowMatch;
      }

      // :
      return $domMatch && $dowMatch;
   }

   /**
    * Next matching unix timestamp strictly after $from (minute granularity).
    *
    * Returns the end of the search horizon when no match is found.
    */
   public function advance (int $from): int
   {
      // ! Start at the next whole minute after $from
      $next = $from - ($from % 60) + 60;

      $limit = $next + (self::MAX_YEARS * 366 * 86400);

      // @
      while ($next <= $limit) {
         if ($this->check($next)) {
            // :
            return $next;
         }

         $next += 60;
      }

      // : No match within the horizon
      return $next;
   }

   /**
    * Parse the expression into per-field allowed-value maps.
    */
   private function parse (): void
   {
      // ? Split on any run of whitespace
      $fields = preg_split('/\s+/', trim($this->expression));

      if ($fields === false || count($fields) !== 5) {
         throw new InvalidArgumentException("Invalid cron expression: {$this->expression}");
      }

      [$minute, $hour, $day, $month, $weekday] = $fields;

      // @
      [$this->minutes,]               = $this->expand($minute, 0, 59);
      [$this->hours,]                 = $this->expand($hour, 0, 23);
      [$this->days, $this->domWild]   = $this->expand($day, 1, 31);
      [$this->months,]                = $this->expand($month, 1, 12);
      [$this->weekdays, $this->dowWild] = $this->expand($weekday, 0, 7);

      // ! Normalize Sunday (7 → 0)
      if (isSet($this->weekdays[7])) {
         $this->weekdays[0] = true;
         unset($this->weekdays[7]);
      }
   }

   /**
    * Expand a single cron field into a map of allowed integers.
    *
    * @return array{0: array<int,true>, 1: bool} The value map and the wildcard flag.
    */
   private function expand (string $field, int $min, int $max): array
   {
      $wild = ($field === '*' || $field === '?');
      $values = [];

      foreach (explode(',', $field) as $part) {
         // # Step (*/n or a-b/n)
         $step = 1;
         if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            $step = max(1, (int) $stepRaw);
         }

         // # Range bounds
         if ($part === '*' || $part === '?') {
            $start = $min;
            $end = $max;
         }
         else if (str_contains($part, '-')) {
            [$startRaw, $endRaw] = explode('-', $part, 2);
            $start = (int) $startRaw;
            $end = (int) $endRaw;
         }
         else {
            $start = (int) $part;
            $end = $start;
         }

         // @ Expand the (stepped) range, clamped to the field bounds
         for ($i = $start; $i <= $end; $i += $step) {
            if ($i >= $min && $i <= $max) {
               $values[$i] = true;
            }
         }
      }

      // :
      return [$values, $wild];
   }
}

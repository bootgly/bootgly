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


use function explode;


/**
 * Named cadences that resolve to a standard 5-field cron expression.
 *
 * The optional `$at` ("HH:MM") sets the time-of-day for cadences coarser than
 * one minute (`Hourly` uses only its minute component).
 */
enum Frequencies
{
   case Minutely;
   case Hourly;
   case Daily;
   case Weekly;
   case Monthly;


   /**
    * Resolve this cadence to a cron expression.
    */
   public function resolve (null|string $at = null): string
   {
      // ! Time-of-day ("HH:MM"), defaulting to midnight
      [$hour, $minute] = $this->parse($at);

      // :
      return match ($this) {
         self::Minutely => '* * * * *',
         self::Hourly   => "$minute * * * *",
         self::Daily    => "$minute $hour * * *",
         self::Weekly   => "$minute $hour * * 0",
         self::Monthly  => "$minute $hour 1 * *",
      };
   }

   /**
    * Parse an "HH:MM" string into [hour, minute] integers.
    *
    * @return array{0: int, 1: int}
    */
   private function parse (null|string $at): array
   {
      $hour = 0;
      $minute = 0;

      // ?
      if ($at !== null && $at !== '') {
         $parts = explode(':', $at);

         $hour = (int) $parts[0];
         $minute = (int) ($parts[1] ?? 0);
      }

      // :
      return [$hour, $minute];
   }
}

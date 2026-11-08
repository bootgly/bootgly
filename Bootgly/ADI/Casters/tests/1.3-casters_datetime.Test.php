<?php

namespace Bootgly\ADI\Casters\Tests\DateTime;


use const INF;
use const NAN;
use function assert;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function get_debug_type;
use function var_export;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Casters;
use Bootgly\ADI\Casters\Modes;


return new Test(
   description: 'Casters: DateTime converts exact date forms (strict) or anything PHP parses (loose)',
   test: function () {
      // ! One cast → `['value' => …]` or `['error' => message]`.
      $cast = static function (mixed $value, Modes $Mode): array {
         try {
            return ['value' => Casters::DateTime->cast($value, $Mode)];
         }
         catch (InvalidArgumentException $Exception) {
            return ['error' => $Exception->getMessage()];
         }
      };
      $format = 'Y-m-d H:i:s.u P';
      $zone = date_default_timezone_get();

      // # Strict accepted: [input, formatted, timezone name]
      $offset = (new DateTimeImmutable('2026-11-08 10:30:00'))->format('P');
      $accepted = [
         ['2026-11-08', "2026-11-08 00:00:00.000000 " . (new DateTimeImmutable('2026-11-08'))->format('P'), $zone],
         ['2026-11-08 10:30:00', "2026-11-08 10:30:00.000000 {$offset}", $zone],
         ['2026-11-08 10:30:00.25', "2026-11-08 10:30:00.250000 {$offset}", $zone],
         ['2026-11-08T10:30:00Z', '2026-11-08 10:30:00.000000 +00:00', 'Z'],
         ['2026-11-08T10:30:00-03:00', '2026-11-08 10:30:00.000000 -03:00', '-03:00'],
         ['2026-11-08T10:30:00.123456+05:30', '2026-11-08 10:30:00.123456 +05:30', '+05:30'],
         ['2024-02-29', '2024-02-29 00:00:00.000000 ' . (new DateTimeImmutable('2024-02-29'))->format('P'), $zone],
      ];
      foreach ($accepted as [$input, $formatted, $timezone]) {
         $Date = $cast($input, Modes::Strict)['value'] ?? null;

         yield assert(
            assertion: $Date instanceof DateTimeImmutable
               && $Date->format($format) === $formatted
               && $Date->getTimezone()->getName() === $timezone,
            description: "Strict DateTime accepts '{$input}'"
         );
      }

      // # Strict refused: impossible values, other shapes, timestamps
      $refused = [
         '2026-02-30',
         '2025-02-29',
         '2026-13-01',
         '2026-11-08 25:00:00',
         '2026-11-08 10:61:00',
         '2026-11-8',
         '26-11-08',
         '2026-11-08T10:30:00',
         '2026-11-08 10:30:00Z',
         '2026-11-08T10:30:00.1234567Z',
         '2026-11-08T10:30:00+24:00',
         '2026-11-08T10:30:00+05:60',
         '2026-02-28T10:00:00+99:99',
         ' 2026-11-08',
         "2026-11-08\n",
         'now',
         'tomorrow',
         '',
         1762598400,
         1762598400.5,
         true,
         null,
         [],
         new stdClass,
      ];
      foreach ($refused as $input) {
         $result = $cast($input, Modes::Strict);
         $shown = get_debug_type($input) === 'string' ? var_export($input, true) : get_debug_type($input);

         yield assert(
            assertion: ($result['error'] ?? null) === 'Cannot cast the value to date-time.',
            description: "Strict DateTime refuses {$shown}"
         );
      }

      // # Strict: a wall time the default timezone skips (DST gap) is refused, not shifted
      $previous = date_default_timezone_get();
      date_default_timezone_set('America/New_York');
      try {
         $gap = $cast('2026-03-08 02:30:00', Modes::Strict);
         $after = $cast('2026-03-08 03:30:00', Modes::Strict)['value'] ?? null;
      }
      finally {
         date_default_timezone_set($previous);
      }
      yield assert(
         assertion: ($gap['error'] ?? null) === 'Cannot cast the value to date-time.'
            && $after instanceof DateTimeImmutable
            && $after->format('Y-m-d H:i:s T') === '2026-03-08 03:30:00 EDT',
         description: 'Strict DateTime refuses 02:30 on a spring-forward day and keeps 03:30'
      );

      // # Both modes: a date-time object becomes an equal immutable
      $Mutable = new DateTime('2026-11-08 10:30:00.5', new DateTimeZone('America/Sao_Paulo'));
      foreach ([Modes::Strict, Modes::Loose] as $Mode) {
         $Date = $cast($Mutable, $Mode)['value'] ?? null;

         yield assert(
            assertion: $Date instanceof DateTimeImmutable
               && $Date->format($format) === $Mutable->format($format)
               && $Date->getTimezone()->getName() === 'America/Sao_Paulo',
            description: "{$Mode->name} DateTime keeps a DateTime's instant and zone"
         );
      }

      // # Loose: timestamps and anything PHP parses
      $Stamp = $cast(1762598400, Modes::Loose)['value'] ?? null;
      yield assert(
         assertion: $Stamp instanceof DateTimeImmutable && $Stamp->format('U') === '1762598400',
         description: 'Loose DateTime accepts a Unix timestamp'
      );

      $Fraction = $cast(1762598400.5, Modes::Loose)['value'] ?? null;
      yield assert(
         assertion: $Fraction instanceof DateTimeImmutable && $Fraction->format('U.u') === '1762598400.500000',
         description: 'Loose DateTime accepts a fractional Unix timestamp'
      );

      $Parsed = $cast('8 November 2026 10:30 UTC', Modes::Loose)['value'] ?? null;
      yield assert(
         assertion: $Parsed instanceof DateTimeImmutable && $Parsed->format('Y-m-d H:i e') === '2026-11-08 10:30 UTC',
         description: 'Loose DateTime accepts a string PHP parses'
      );

      // # Loose refused: blank (PHP's "now"), garbage, unrepresentable timestamps, non-dates
      foreach (['', '   ', 'not a date', 1e300, INF, NAN, true, null, []] as $input) {
         $result = $cast($input, Modes::Loose);
         $shown = get_debug_type($input) === 'string' ? var_export($input, true) : get_debug_type($input);

         yield assert(
            assertion: ($result['error'] ?? null) === 'Cannot cast the value to date-time.',
            description: "Loose DateTime refuses {$shown}"
         );
      }
   }
);

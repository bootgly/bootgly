<?php

namespace Bootgly\ADI\Casters\Tests\Strict;


use const INF;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;
use function assert;
use function get_debug_type;
use function hrtime;
use function implode;
use function str_contains;
use function str_repeat;
use function var_export;
use InvalidArgumentException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Casters;
use Bootgly\ADI\Casters\Modes;


return new Test(
   description: 'Casters: strict mode converts exact forms only and never echoes a refused value',
   test: function () {
      // ! One cast → `['value' => …]` or `['error' => message]`.
      $cast = static function (Casters $Caster, mixed $value): array {
         try {
            return ['value' => $Caster->cast($value, Modes::Strict)];
         }
         catch (InvalidArgumentException $Exception) {
            return ['error' => $Exception->getMessage()];
         }
      };

      // # Accepted: [caster, input, expected] (compared with `===`)
      $accepted = [
         [Casters::Integer, 42, 42],
         [Casters::Integer, PHP_INT_MIN, PHP_INT_MIN],
         [Casters::Integer, '42', 42],
         [Casters::Integer, '-7', -7],
         [Casters::Integer, '+5', 5],
         [Casters::Integer, '-0', 0],
         [Casters::Integer, '9223372036854775807', PHP_INT_MAX],
         [Casters::Integer, '-9223372036854775808', PHP_INT_MIN],
         [Casters::Float, 2, 2.0],
         [Casters::Float, 1.5, 1.5],
         [Casters::Float, '10.25', 10.25],
         [Casters::Float, '.5', 0.5],
         [Casters::Float, '5.', 5.0],
         [Casters::Float, '1e3', 1000.0],
         [Casters::Float, '-1.5e-3', -0.0015],
         [Casters::Float, '007', 7.0],
         [Casters::Boolean, true, true],
         [Casters::Boolean, false, false],
         [Casters::Boolean, 1, true],
         [Casters::Boolean, 0, false],
         [Casters::Boolean, '1', true],
         [Casters::Boolean, 'yes', true],
         [Casters::Boolean, ' On ', true],
         [Casters::Boolean, 'TRUE', true],
         [Casters::Boolean, '0', false],
         [Casters::Boolean, 'no', false],
         [Casters::Boolean, 'Off', false],
         [Casters::Boolean, "false\n", false],
         [Casters::String, 'abc', 'abc'],
         [Casters::String, ' 1', ' 1'],
         [Casters::String, 42, '42'],
         [Casters::String, 1.5, '1.5'],
         [Casters::String, true, '1'],
         [Casters::String, false, ''],
      ];
      foreach ($accepted as [$Caster, $input, $expected]) {
         $result = $cast($Caster, $input);
         $shown = var_export($input, true);

         yield assert(
            assertion: ($result['value'] ?? null) === $expected,
            description: "{$Caster->name} accepts {$shown} as " . var_export($expected, true)
               . ' (got ' . get_debug_type($result['value'] ?? null) . ')'
         );
      }

      // # Refused: [caster, input]
      $refused = [
         [Casters::Integer, ' 1'],
         [Casters::Integer, '1 '],
         [Casters::Integer, "1\n"],
         [Casters::Integer, '007'],
         [Casters::Integer, '0x1A'],
         [Casters::Integer, '1e3'],
         [Casters::Integer, '1.0'],
         [Casters::Integer, '1_000'],
         [Casters::Integer, '12abc'],
         [Casters::Integer, ''],
         [Casters::Integer, '9223372036854775808'],
         [Casters::Integer, '-9223372036854775809'],
         [Casters::Integer, 1.0],
         [Casters::Integer, true],
         [Casters::Float, true],
         [Casters::Float, INF],
         [Casters::Float, NAN],
         [Casters::Float, '1e999'],
         [Casters::Float, 'INF'],
         [Casters::Float, ' 1.5'],
         [Casters::Float, '1,5'],
         [Casters::Float, '1.2.3'],
         [Casters::Float, ''],
         [Casters::Boolean, 2],
         [Casters::Boolean, -1],
         [Casters::Boolean, 1.0],
         [Casters::Boolean, 0.0],
         [Casters::Boolean, ''],
         [Casters::Boolean, ' '],
         [Casters::Boolean, 'y'],
         [Casters::Boolean, 'maybe'],
         [Casters::Boolean, 'null'],
         [Casters::Integer, null],
         [Casters::Float, []],
         [Casters::Boolean, new stdClass],
         [Casters::String, null],
         [Casters::String, ['a']],
         [Casters::String, new stdClass],
      ];
      $names = [
         'Integer' => 'integer',
         'Float' => 'float',
         'Boolean' => 'boolean',
         'String' => 'string',
      ];
      foreach ($refused as [$Caster, $input]) {
         $result = $cast($Caster, $input);
         $shown = get_debug_type($input) === 'string' ? var_export($input, true) : get_debug_type($input);

         yield assert(
            assertion: ($result['error'] ?? null) === "Cannot cast the value to {$names[$Caster->name]}.",
            description: "{$Caster->name} refuses {$shown}"
         );
      }

      // # A long input is refused in linear time (no regex backtracking blow-up)
      $slow = [];
      foreach ([Casters::Float, Casters::Integer, Casters::Boolean, Casters::DateTime] as $Caster) {
         $started = hrtime(true);
         $result = $cast($Caster, str_repeat('1', 1_000_000) . 'x');
         $elapsed = (hrtime(true) - $started) / 1e6;

         if (isset($result['error']) === false || $elapsed > 500) {
            $slow[] = "{$Caster->name} ({$elapsed} ms)";
         }
      }
      yield assert(
         assertion: $slow === [],
         description: 'A 1 MB digit run is refused well under 500 ms by every strict caster — ' . implode(', ', $slow)
      );

      // # A refusal never carries the value (it may be a secret)
      $secret = 's3cr3t-p4ss';
      $leaks = false;
      foreach ([Casters::Integer, Casters::Float, Casters::Boolean] as $Caster) {
         $error = $cast($Caster, $secret)['error'] ?? '';
         $leaks = $leaks || $error === '' || str_contains($error, $secret);
      }

      yield assert(
         assertion: $leaks === false,
         description: 'A refused secret-like value is absent from every refusal message'
      );
   }
);

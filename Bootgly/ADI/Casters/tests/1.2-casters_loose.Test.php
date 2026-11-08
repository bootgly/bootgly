<?php

namespace Bootgly\ADI\Casters\Tests\Loose;


use const INF;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;
use function assert;
use function get_debug_type;
use function restore_error_handler;
use function set_error_handler;
use function var_export;
use InvalidArgumentException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Casters;
use Bootgly\ADI\Casters\Modes;


return new Test(
   description: 'Casters: loose mode follows PHP casts and refuses only an int the range cannot hold',
   test: function () {
      // ! One cast → `['value' => …, 'warned' => bool]` or `['error' => message]`.
      $cast = static function (Casters $Caster, mixed $value): array {
         $warned = false;
         set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
         });

         try {
            return ['value' => $Caster->cast($value, Modes::Loose), 'warned' => $warned];
         }
         catch (InvalidArgumentException $Exception) {
            return ['error' => $Exception->getMessage()];
         }
         finally {
            restore_error_handler();
         }
      };

      // # Accepted: PHP's own casts — [caster, input, expected]
      $accepted = [
         [Casters::Integer, 42, 42],
         [Casters::Integer, '42', 42],
         [Casters::Integer, '-0', 0],
         [Casters::Integer, '007', 7],
         [Casters::Integer, ' 1', 1],
         [Casters::Integer, "1\n", 1],
         [Casters::Integer, '1e3', 1000],
         [Casters::Integer, '1.9', 1],
         [Casters::Integer, '12abc', 12],
         [Casters::Integer, 'abc', 0],
         [Casters::Integer, '', 0],
         [Casters::Integer, 1.9, 1],
         [Casters::Integer, -1.9, -1],
         [Casters::Integer, true, 1],
         [Casters::Integer, '9223372036854775807', PHP_INT_MAX],
         [Casters::Integer, '-9223372036854775808', PHP_INT_MIN],
         [Casters::Integer, ' 9223372036854775807 ', PHP_INT_MAX],
         [Casters::Integer, '+00009223372036854775807', PHP_INT_MAX],
         [Casters::Integer, -9.2233720368547758E18, PHP_INT_MIN],
         [Casters::Integer, '9223372036854775807.0', PHP_INT_MAX],
         [Casters::Integer, '9007199254740993.0', 9007199254740993],
         [Casters::Integer, '-0.0', 0],
         [Casters::Integer, '5.', 5],
         [Casters::Float, '1.5', 1.5],
         [Casters::Float, 'abc', 0.0],
         [Casters::Float, 3, 3.0],
         [Casters::Float, true, 1.0],
         [Casters::Float, INF, INF],
         [Casters::Boolean, 'false', true],
         [Casters::Boolean, '0', false],
         [Casters::Boolean, '', false],
         [Casters::Boolean, 2, true],
         [Casters::Boolean, 0.0, false],
         [Casters::String, 42, '42'],
         [Casters::String, true, '1'],
         [Casters::String, 1.5, '1.5'],
      ];
      foreach ($accepted as [$Caster, $input, $expected]) {
         $result = $cast($Caster, $input);
         $shown = var_export($input, true);

         yield assert(
            assertion: ($result['value'] ?? null) === $expected && ($result['warned'] ?? true) === false,
            description: "Loose {$Caster->name} casts {$shown} like PHP, without a warning"
         );
      }

      // # Refused: a numeric value the int range cannot hold, never saturated or wrapped
      $beyond = [
         '9223372036854775808',
         '-9223372036854775809',
         ' 9223372036854775808',
         '18446744073709551615',
         '9223372036854775808.0',
         '1e30',
         '1e999',
         9.2233720368547758E18,
         -9.2233720368547799E18,
         1e30,
         INF,
         -INF,
         NAN,
      ];
      foreach ($beyond as $input) {
         $result = $cast(Casters::Integer, $input);
         $shown = var_export($input, true);

         yield assert(
            assertion: ($result['error'] ?? null) === 'Cannot cast the value to integer: it is beyond the int range.',
            description: "Loose Integer refuses {$shown}"
         );
      }

      // # Refused: anything that is not a scalar
      foreach ([Casters::Integer, Casters::Float, Casters::Boolean, Casters::String] as $Caster) {
         foreach ([null, [], new stdClass] as $input) {
            $result = $cast($Caster, $input);

            yield assert(
               assertion: isset($result['error']),
               description: "Loose {$Caster->name} refuses " . get_debug_type($input)
            );
         }
      }
   }
);

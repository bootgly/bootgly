<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs\Config\Types;


return new Test(
   description: 'Config Types: strict parsing is the ADI Casters strict mode, edge forms pinned',
   test: function () {
      // ! One parse → the value, or the refusal message.
      $cast = static function (Types $Type, string|int|float|bool $value): mixed {
         try {
            return $Type->cast($value);
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }
      };

      // # Accepted: [type, input, expected]
      $accepted = [
         [Types::Integer, '+5', 5],
         [Types::Integer, '-0', 0],
         [Types::Integer, '9223372036854775807', PHP_INT_MAX],
         [Types::Float, '1e3', 1000.0],
         [Types::Float, '007', 7.0],
         [Types::Float, 2, 2.0],
         [Types::Boolean, 'yes', true],
         [Types::Boolean, ' On ', true],
         [Types::Boolean, 'no', false],
         [Types::Boolean, 1, true],
         [Types::String, true, '1'],
         [Types::String, false, ''],
         [Types::String, ' 1', ' 1'],
      ];
      foreach ($accepted as [$Type, $input, $expected]) {
         $shown = var_export($input, true);

         yield assert(
            assertion: $cast($Type, $input) === $expected,
            description: "{$Type->name} accepts {$shown}"
         );
      }

      // # Refused with the config message, never the value: [type, input]
      $refused = [
         [Types::Integer, ' 1'],
         [Types::Integer, '007'],
         [Types::Integer, '0x1A'],
         [Types::Integer, '1e3'],
         [Types::Integer, '9223372036854775808'],
         [Types::Integer, 1.0],
         [Types::Integer, true],
         [Types::Float, 'INF'],
         [Types::Float, '1e999'],
         [Types::Float, INF],
         [Types::Float, true],
         [Types::Boolean, 2],
         [Types::Boolean, 1.0],
         [Types::Boolean, 'y'],
         [Types::Boolean, ''],
      ];
      foreach ($refused as [$Type, $input]) {
         $shown = var_export($input, true);
         $type = strtolower($Type->name);

         yield assert(
            assertion: $cast($Type, $input) === "Invalid {$type} config value.",
            description: "{$Type->name} refuses {$shown}"
         );
      }
   }
);

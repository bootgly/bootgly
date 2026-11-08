<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\HydrationCasts;


use const INF;
use const NAN;
use function assert;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function var_export;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;


#[Table('hydration_cast_rows')]
class Row
{
   #[Key]
   public null|int $id = null;
   #[Column(nullable: true)]
   public null|int $counter = null;
   #[Column(nullable: true)]
   public null|float $ratio = null;
   #[Column(nullable: true)]
   public null|bool $active = null;
   #[Column(nullable: true)]
   public null|string $label = null;
}


return new Test(
   description: 'ORM: hydration casts follow PHP (Casters loose mode); an int the range cannot hold is refused, never wrapped',
   test: function () {
      $Database = new SQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);

      // ! Hydrate one row → ['entity', 'error', 'warned'].
      $hydrate = static function (array $row) use ($Database): array {
         $warned = false;
         set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
         });

         try {
            $Mapped = $Database->map(Row::class)->hydrate(new Result('SELECT 1', [['id' => 1] + $row]));

            return ['entity' => $Mapped->entity, 'error' => null, 'warned' => $warned];
         }
         catch (Throwable $Thrown) {
            return ['entity' => null, 'error' => $Thrown->getMessage(), 'warned' => $warned];
         }
         finally {
            restore_error_handler();
         }
      };

      // # A float the int range cannot hold, INF and NAN: refused (before 1.2.0 they wrapped to PHP_INT_MIN / 0)
      foreach ([9.2233720368547758E18, -1e30, INF, -INF, NAN, '1e999', '1e30'] as $input) {
         $result = $hydrate(['counter' => $input]);
         $shown = var_export($input, true);

         yield assert(
            assertion: $result['error'] !== null
               && str_starts_with($result['error'], 'ORM cannot hydrate a value beyond PHP_INT_MAX into an int property: ' . Row::class . '::$counter (')
               && $result['warned'] === false,
            description: "An int column holding {$shown} is refused without a PHP warning"
         );
      }

      // # A decimal string with a trailing newline casts like PHP (before 1.2.0: a false overflow error)
      $newline = $hydrate(['counter' => "7\n"]);
      yield assert(
         assertion: $newline['error'] === null && $newline['entity']->counter === 7,
         description: 'An int column holding "7\n" hydrates as 7'
      );

      $padded = $hydrate(['counter' => " 9223372036854775808"]);
      yield assert(
         assertion: $padded['error'] !== null,
         description: 'A whitespace-padded decimal past the range is refused, not saturated'
      );

      // # Ordinary driver shapes keep PHP's casts (parity with 1.1)
      $shapes = [
         [['counter' => 7], 'counter', 7],
         [['counter' => '42'], 'counter', 42],
         [['counter' => '1.9'], 'counter', 1],
         [['counter' => 1.9], 'counter', 1],
         [['counter' => true], 'counter', 1],
         [['counter' => '12abc'], 'counter', 12],
         [['ratio' => '1.5'], 'ratio', 1.5],
         [['ratio' => 2], 'ratio', 2.0],
         [['ratio' => 'abc'], 'ratio', 0.0],
         [['active' => 1], 'active', true],
         [['active' => '0'], 'active', false],
         [['active' => 'false'], 'active', true],
         [['label' => 42], 'label', '42'],
         [['label' => true], 'label', '1'],
         [['label' => 'x'], 'label', 'x'],
      ];
      foreach ($shapes as [$row, $property, $expected]) {
         $result = $hydrate($row);
         $shown = var_export($row[$property], true);

         yield assert(
            assertion: $result['error'] === null && $result['entity']->{$property} === $expected,
            description: "Column {$property} holding {$shown} hydrates as " . var_export($expected, true)
         );
      }
   }
);

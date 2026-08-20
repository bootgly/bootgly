<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\HydrationWidth;


use function assert;
use function get_debug_type;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;


#[Table('hydration_width_rows')]
class Narrow
{
   #[Key]
   public null|int $id = null;
   #[Column(nullable: true)]
   public null|int $counter = null;
}

#[Table('hydration_width_rows')]
class Wide
{
   #[Key]
   public null|int|string $id = null;
   #[Column(nullable: true)]
   public null|int $counter = null;
}


return new Test(
   description: 'ORM: hydrating a value past PHP_INT_MAX into an int property is refused, not saturated',
   test: function () {
      $Database = new SQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $beyond = '18446744073709551615';

      $hydrate = static function (string $class, array $row) use ($Database): array {
         $Repository = $Database->map($class);

         try {
            $Mapped = $Repository->hydrate(new Result('SELECT 1', [$row]));

            return ['entity' => $Mapped->entity, 'error' => null];
         }
         catch (Throwable $Thrown) {
            return ['entity' => null, 'error' => $Thrown->getMessage()];
         }
      };

      // # A key wider than an int is refused where it cannot be held
      //   The MySQL decoder hands back an exact decimal string for a
      //   `BIGINT UNSIGNED` past 2^63. Narrowing it saturates to PHP_INT_MAX,
      //   and the entity then carries a key that matches no row: the next
      //   save() targets nothing and reports success.
      $refused = $hydrate(Narrow::class, ['id' => $beyond]);

      yield assert(
         assertion: $refused['error'] === 'ORM cannot hydrate a value beyond PHP_INT_MAX into an int property: '
            . Narrow::class . '::$id (' . $beyond . ') — declare it as null|int|string.',
         description: 'A key past PHP_INT_MAX in an int property is refused with the remedy'
      );

      // # The declared-wide property still hydrates it exactly
      $wide = $hydrate(Wide::class, ['id' => $beyond]);

      yield assert(
         assertion: $wide['error'] === null
            && $wide['entity']->id === $beyond
            && get_debug_type($wide['entity']->id) === 'string',
         description: 'A property that accepts a string keeps the value exactly'
      );

      // # It is not only about keys
      //   cast() runs on every hydrated column, so an ordinary counter past the
      //   range is refused too — the blast radius this fix deliberately accepts.
      $counter = $hydrate(Wide::class, ['id' => '1', 'counter' => $beyond]);

      yield assert(
         assertion: $counter['error'] !== null
            && str_contains($counter['error'], '$counter'),
         description: 'A non-key column past the range is refused the same way'
      );

      // # Ordinary values still narrow
      $ordinary = $hydrate(Narrow::class, ['id' => '42', 'counter' => 7]);

      yield assert(
         assertion: $ordinary['error'] === null
            && $ordinary['entity']->id === 42
            && $ordinary['entity']->counter === 7,
         description: 'A value that fits is narrowed as before'
      );

      // # Shapes that are not overflow must not be mistaken for it
      //   Leading zeros, an explicit sign and a negative zero all round-trip to
      //   something other than their input, and none of them loses precision.
      $shapes = [
         ['id' => '007', 'expected' => 7],
         ['id' => '+5', 'expected' => 5],
         ['id' => '-0', 'expected' => 0],
         ['id' => '-9223372036854775808', 'expected' => PHP_INT_MIN],
         ['id' => '9223372036854775807', 'expected' => PHP_INT_MAX],
      ];
      $mistaken = [];

      foreach ($shapes as $shape) {
         $result = $hydrate(Narrow::class, ['id' => $shape['id']]);

         if ($result['error'] !== null || $result['entity']->id !== $shape['expected']) {
            $mistaken[] = $shape['id'];
         }
      }

      yield assert(
         assertion: $mistaken === [],
         description: 'Leading zeros, signs and the range boundaries are not overflow'
      );
   }
);

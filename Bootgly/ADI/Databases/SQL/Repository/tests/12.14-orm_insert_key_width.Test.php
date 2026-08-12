<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Width;


use function assert;
use function str_contains;
use function str_starts_with;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;


#[Table('width_wide')]
class Wide
{
   #[Key]
   public null|int|string $id = null;
   #[Column]
   public string $name = '';
}

#[Table('width_narrow')]
class Narrow
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

class RecordingSQL extends SQL
{
   /** @var array<int,string> */
   public array $queries = [];
   public null|Result $Next = null;


   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $this->queries[] = $Normalized->SQL;

      $Operation->resolve($this->Next ?? new Result('OK'));
      $this->Next = null;

      return $Operation;
   }
}


return new Test(
   description: 'ORM: a generated key beyond PHP_INT_MAX backfills exactly or refuses, never silently',
   test: function () {
      // ! MySQL reports `last_insert_id` as an exact decimal string once the
      //   value passes the sign bit — no PHP int is wide enough for it.
      $inserted = '18446744073709551615';
      $Oversized = null;

      try {
         $Oversized = new Result('INSERT 0 1', [], [], 1, $inserted);
      }
      catch (Throwable) {
         // ? Result still narrows the generated id to int — the yield below reports it.
      }

      yield assert(
         assertion: $Oversized instanceof Result && $Oversized->inserted === $inserted,
         description: 'Result carries a generated id beyond PHP_INT_MAX exactly'
      );

      if ($Oversized === null) {
         return;
      }


      // # A key property that accepts a string takes the exact value
      $Database = new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $Repository = $Database->map(Wide::class);

      $Entity = new Wide;
      $Entity->name = 'Ada';

      $Database->Next = $Oversized;
      $Mapped = $Repository->hydrate($Repository->save($Entity));

      yield assert(
         assertion: $Mapped->entity === $Entity && $Entity->id === $inserted,
         description: 'A key declared as null|int|string backfills the exact generated id'
      );

      // @ Identity registration must route the next save() to an UPDATE — the
      //   duplicate INSERT is the damage this whole contract exists to prevent.
      $Entity->name = 'Ada Lovelace';
      $Database->Next = new Result('UPDATE 1', [], [], 1);
      $Repository->save($Entity);

      yield assert(
         assertion: str_starts_with($Database->queries[1], 'UPDATE `width_wide`'),
         description: 'The entity carrying an oversized key updates instead of inserting again'
      );


      // # A key property that only accepts an int refuses, loudly
      $Narrow = new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $NarrowRepository = $Narrow->map(Narrow::class);

      $Refused = new Narrow;
      $Refused->name = 'Grace';

      $Narrow->Next = new Result('INSERT 0 1', [], [], 1, $inserted);
      $thrown = null;

      try {
         $NarrowRepository->hydrate($NarrowRepository->save($Refused));
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable;
      }

      yield assert(
         assertion: $thrown instanceof RuntimeException
            && str_contains($thrown->getMessage(), 'Narrow::$id')
            && str_contains($thrown->getMessage(), $inserted),
         description: 'An int-only key refuses an oversized generated id and names the property'
      );

      yield assert(
         assertion: $Refused->id === null,
         description: 'The refused backfill leaves the key untouched instead of saturating it'
      );


      // # Control — a generated id inside int range is unchanged
      $Small = new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $SmallRepository = $Small->map(Narrow::class);

      $Fits = new Narrow;
      $Fits->name = 'Edsger';

      $Small->Next = new Result('INSERT 0 1', [], [], 1, 7);
      $Mapped = $SmallRepository->hydrate($SmallRepository->save($Fits));

      yield assert(
         assertion: $Mapped->entity === $Fits && $Fits->id === 7,
         description: 'A generated id inside int range still backfills as an int'
      );
   }
);

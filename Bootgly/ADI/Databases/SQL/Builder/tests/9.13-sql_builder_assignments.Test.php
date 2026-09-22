<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Assignments;


use function assert;
use function json_encode;
use Error;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


return new Test(
   description: 'Database: SQL builder exposes its assignments read-only, keyed by compiled column',
   test: function () {
      $Builder = (new Builder)
         ->table(new Identifier('polls'))
         ->insert()
         ->set(new Identifier('id'), 1, 2)
         ->set(new Identifier('question'), 'A?', 'B?');

      yield assert(
         assertion: $Builder->assignments === ['"id"' => [1, 2], '"question"' => ['A?', 'B?']],
         description: 'Assignments map each quoted column to one value per row, found: '
            . json_encode($Builder->assignments)
      );

      $Builder = (new Builder(new MySQL))
         ->table(new Identifier('polls'))
         ->insert()
         ->set(new Identifier('id'), 7)
         ->set(new Expression('legacy_id'), 8);

      yield assert(
         assertion: $Builder->assignments === ['`id`' => [7], 'legacy_id' => [8]],
         description: 'Keys follow the dialect quoting and an Expression column stays raw SQL, found: '
            . json_encode($Builder->assignments)
      );

      $blocked = false;
      try {
         $Builder->assignments = [];
      }
      catch (Error) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked && $Builder->assignments === ['`id`' => [7], 'legacy_id' => [8]],
         description: 'Assignments cannot be written from outside the builder'
      );
   }
);

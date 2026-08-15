<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should return exactly what the native idiom returns, for every shape',
   test: function () {
      // ! Deterministic inputs — a benchmark or a contract test that cannot be
      //   reproduced is not evidence
      $Sources = [
         'empty' => [],
         'single' => [7],
         'sequence' => range(1, 40),
         'negatives' => range(-20, 20),
         'map' => array_combine(range('a', 'j'), range(1, 10)),
         'sparse' => [3 => 1, 9 => 2, 27 => 3, 81 => 4],
         'mixed keys' => ['x' => 5, 0 => 6, 'y' => 7, 1 => 8]
      ];

      $Double = static fn (int $value): int => $value * 2;
      $Increment = static fn (int $value): int => $value + 1;
      $Third = static fn (int $value): bool => $value % 3 === 0;
      $Positive = static fn (int $value): bool => $value > 0;

      // ! Every recorded shape, against the native expression it replaces
      $Shapes = [
         'no stages' => [
            static fn (array $a): array => new Pipeline($a)->collect(),
            static fn (array $a): array => array_values($a)
         ],
         'map' => [
            static fn (array $a): array => new Pipeline($a)->map($Double)->collect(),
            static fn (array $a): array => array_values(array_map($Double, $a))
         ],
         'filter' => [
            static fn (array $a): array => new Pipeline($a)->filter($Third)->collect(),
            static fn (array $a): array => array_values(array_filter($a, $Third))
         ],
         'map -> filter' => [
            static fn (array $a): array => new Pipeline($a)->map($Double)->filter($Third)->collect(),
            static fn (array $a): array => array_values(array_filter(array_map($Double, $a), $Third))
         ],
         'filter -> map' => [
            static fn (array $a): array => new Pipeline($a)->filter($Third)->map($Double)->collect(),
            static fn (array $a): array => array_values(array_map($Double, array_filter($a, $Third)))
         ],
         'map -> map' => [
            static fn (array $a): array => new Pipeline($a)->map($Double)->map($Increment)->collect(),
            static fn (array $a): array => array_values(array_map($Increment, array_map($Double, $a)))
         ],
         'filter -> filter' => [
            static fn (array $a): array => new Pipeline($a)->filter($Third)->filter($Positive)->collect(),
            static fn (array $a): array => array_values(array_filter(array_filter($a, $Third), $Positive))
         ],
         'map -> filter -> map -> filter' => [
            static fn (array $a): array => new Pipeline($a)
               ->map($Double)->filter($Third)->map($Increment)->filter($Positive)->collect(),
            static fn (array $a): array => array_values(array_filter(
               array_map($Increment, array_filter(array_map($Double, $a), $Third)),
               $Positive
            ))
         ]
      ];

      // @@
      foreach ($Shapes as $shape => [$Chained, $Native]) {
         $agreed = true;
         $where = '';

         foreach ($Sources as $source => $array) {
            if ($Chained($array) !== $Native($array)) {
               $agreed = false;
               $where = $source;

               break;
            }
         }

         yield assert(
            assertion: $agreed,
            description: "Shape '{$shape}' matches the native idiom"
               . ($agreed ? '' : " — disagreed on '{$where}'")
         );
      }

      // ---

      // ! The terminals agree with their own native equivalents
      $source = range(1, 40);

      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Third)->find()
            === array_find(array_map($Double, $source), $Third),
         description: 'find() matches native array_find() over the mapped array'
      );

      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Third)->check()
            === array_any(array_map($Double, $source), $Third),
         description: 'check() matches native array_any() over the mapped array'
      );

      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Third)->count()
            === count(array_filter(array_map($Double, $source), $Third)),
         description: 'count() matches count(array_filter(array_map(...)))'
      );

      // ! apply() agrees with collect() over the same array
      $Pipeline = new Pipeline()->map($Double)->filter($Third);

      $agreed = true;
      foreach ($Sources as $array) {
         if ($Pipeline->apply($array) !== new Pipeline($array)->map($Double)->filter($Third)->collect()) {
            $agreed = false;

            break;
         }
      }

      yield assert(
         assertion: $agreed,
         description: 'A reused pipeline agrees with a per-call one on every source'
      );
   }
);

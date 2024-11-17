<?php

return [
   // @ configure
   'describe' => 'It should assert returning boolean (retestable)',
   // @ simulate
   // ...
   // @ test
   'test' => function (bool $expected = false): bool
   {
      return true === $expected;
   },
   'retest' => function (callable $test, bool $passed, mixed ...$arguments): string|bool|null
   {
      // ? If the last test failed, retest changing dataset
      if ($passed === false) {
         return $test(true);
      }

      return null;
   }
];

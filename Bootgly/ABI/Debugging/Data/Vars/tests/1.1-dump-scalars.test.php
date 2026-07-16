<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use const INF;
use const NAN;
use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should dump scalars with exact literals',
   test: function () {
      $Dumper = new Dumper('plain');

      // @ null and booleans
      yield assert(
         assertion: $Dumper->dump(null) === 'null'
            && $Dumper->dump(true) === 'true'
            && $Dumper->dump(false) === 'false',
         description: 'null and booleans dump as lowercase literals'
      );

      // @ Integers
      yield assert(
         assertion: $Dumper->dump(42) === '42'
            && $Dumper->dump(-7) === '-7',
         description: 'Integers dump verbatim'
      );

      // @ Floats — var_export precision: whole floats keep `.0`
      yield assert(
         assertion: $Dumper->dump(1.0) === '1.0'
            && $Dumper->dump(3.0) === '3.0'
            && $Dumper->dump(-0.0) === '-0.0'
            && $Dumper->dump(0.1 + 0.2) === '0.30000000000000004'
            && $Dumper->dump(1e20) === '1.0E+20',
         description: 'Floats dump precisely and whole floats keep the decimal point'
      );

      // @ Non-finite floats
      yield assert(
         assertion: $Dumper->dump(INF) === 'INF'
            && $Dumper->dump(-INF) === '-INF'
            && $Dumper->dump(NAN) === 'NAN',
         description: 'Non-finite floats dump as bare INF/-INF/NAN'
      );

      // @ Strings — single-quoted
      yield assert(
         assertion: $Dumper->dump('Hello') === "'Hello'"
            && $Dumper->dump('') === "''",
         description: 'Strings dump single-quoted'
      );

      // @ Control chars — C mnemonics + octal escapes (SGR-injection-proof)
      yield assert(
         assertion: $Dumper->dump("a\nb") === "'a\\nb'"
            && $Dumper->dump("a\tb") === "'a\\tb'"
            && $Dumper->dump("esc\e[31m") === "'esc\\033[31m'"
            && $Dumper->dump("del\x7F!") === "'del\\177!'"
            && $Dumper->dump("qu'ote") === "'qu\\'ote'",
         description: 'Control chars escape visibly — ESC as octal, never raw'
      );
   }
);

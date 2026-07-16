<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function dump;
use function preg_replace;
use function str_contains;

use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification;


class DumpDelegate
{
   public bool $flag = true;
   protected string $inner = 'shielded';
}

return new Specification(
   description: 'It should delegate the CLI value rendering of Vars to the Dumper',
   test: function () {
      // ! Route Vars accumulation without printing or exiting
      Vars::$debug = true;
      Vars::$print = false;
      Vars::$exit = false;

      // @
      dump(['probe' => new DumpDelegate]);

      $output = Vars::output(vars: true);
      // SGR-painted by the default `bootgly` theme — strip before asserting
      $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);

      yield assert(
         assertion: str_contains($stripped, '+flag: true') === true
            && str_contains($stripped, "#inner: 'shielded'") === true,
         description: 'dump() expands object properties through the Dumper engine'
      );

      yield assert(
         assertion: str_contains($output, "\e[") === true,
         description: 'The CLI target paints with the default bootgly theme'
      );

      // ---
      // teardown — statics leak between suites; reset() skips these
      Vars::$debug = false;
      Vars::$print = true;
      Vars::$exit = true;
      Vars::reset();
      Backtrace::$traces = 4;
   }
);

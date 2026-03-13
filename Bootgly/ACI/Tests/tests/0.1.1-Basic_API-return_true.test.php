<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


return new Specification(
   Separator: new Separator(line: 'Basic API'),
   description: 'It should assert returning true',
   test: function (): bool
   {
      return true === true;
   }
);

<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suite\Test\Separator;


return new Test(
   Separator: new Separator(line: 'Basic API'),
   description: 'It should assert returning true',
   test: function (): bool
   {
      return true === true;
   }
);

<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Authentication;


return new Specification(
   description: 'Database: PostgreSQL MD5 authentication response',
   test: function () {
      $Config = new Config([
         'password' => 'secret',
         'username' => 'bootgly',
      ]);
      $Authentication = new Authentication($Config);

      yield assert(
         assertion: $Authentication->hash("\x12\x34\x56\x78") === 'md5acf6aadc2e2fc6fbdb32081f1e7024d9',
         description: 'Authentication builds PostgreSQL MD5 password response'
      );
   }
);

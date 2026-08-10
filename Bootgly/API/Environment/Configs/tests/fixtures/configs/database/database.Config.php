<?php

use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'database')
   ->Default->bind(key: 'DB_CONNECTION', default: 'mysql')
   ->Connections
      ->MySQL
         ->Driver->bind(key: '', default: 'mysql')
         ->Host->bind(key: 'DB_HOST', default: 'localhost')
         ->Port->bind(key: 'DB_PORT', default: 3306, cast: Types::Integer)
         ->Database->bind(key: 'DB_NAME', default: 'bootgly')
         ->Username->bind(key: 'DB_USER', default: 'root')
         ->Password->bind(key: 'DB_PASS', default: '')
         ->Charset->bind(key: '', default: 'utf8mb4');

<?php

use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'database')
   ->Default->bind(key: '', default: 'pgsql')
   ->Connections
      ->MySQL
         ->Host->bind(key: 'DB_HOST', default: 'localhost')
         ->Port->bind(key: 'DB_PORT', default: 3306, cast: Types::Integer)
         ->Database->bind(key: 'DB_NAME', default: 'bootgly');

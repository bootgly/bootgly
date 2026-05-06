<?php

use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'server')
   ->Host->bind(key: 'SERVER_HOST', default: 'localhost')
   ->Port->bind(key: 'SERVER_PORT', default: 8080, cast: Types::Integer)
   ->Workers->bind(key: 'SERVER_WORKERS', default: 4, cast: Types::Integer);

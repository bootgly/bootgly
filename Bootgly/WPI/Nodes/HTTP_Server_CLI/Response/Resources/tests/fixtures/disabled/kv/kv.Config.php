<?php

use Bootgly\API\Environment\Configs\Config;


return new Config(scope: 'kv')
   ->Enabled->bind(default: false);

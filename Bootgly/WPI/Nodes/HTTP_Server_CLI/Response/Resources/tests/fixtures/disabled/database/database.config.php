<?php

use Bootgly\API\Environment\Configs\Config;


return new Config(scope: 'database')
   ->Enabled->bind(default: false);

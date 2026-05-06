<?php

use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'policy_locked')
   ->Secret->need(key: 'POLICY_SECRET');

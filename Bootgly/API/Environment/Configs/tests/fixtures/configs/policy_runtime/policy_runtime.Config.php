<?php

use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'policy_runtime')
   ->Secret->bind(key: 'POLICY_SECRET', required: true);

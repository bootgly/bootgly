<?php

use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'policy_bad_name')
   ->Value->bind(key: 'POLICY_VALUE', default: 'fallback');

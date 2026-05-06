<?php

use Bootgly\API\Environment\Configs\Config;

return new Config(scope: 'policy_extra')
   ->Value->bind(key: 'POLICY_VALUE', default: 'fallback');

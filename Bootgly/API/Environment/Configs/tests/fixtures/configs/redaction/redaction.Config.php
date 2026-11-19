<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\API\Environment\Configs\Config;


return new Config(scope: 'redaction')
   ->Secret->bind(key: 'REDACTION_SECRET')
   ->Name->bind(key: 'REDACTION_NAME');

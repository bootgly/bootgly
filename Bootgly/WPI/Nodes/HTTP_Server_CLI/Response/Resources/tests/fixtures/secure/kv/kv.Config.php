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
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'kv')
   ->Enabled->bind(key: 'KV_ENABLED', cast: Types::Boolean)
   ->Driver->bind(key: 'KV_DRIVER')
   ->Host->bind(key: 'KV_HOST')
   ->Port->bind(key: 'KV_PORT', cast: Types::Integer)
   ->Database->bind(key: 'KV_DATABASE')
   ->Password->bind(key: 'KV_PASS')
   ->Timeout->bind(key: 'KV_TIMEOUT', cast: Types::Float)
   ->Secure
      ->Mode->bind(key: 'KV_SSLMODE')
      ->Verify->bind(key: 'KV_SSLVERIFY', cast: Types::Boolean)
      ->Peer->bind(key: 'KV_SSLPEER')
      ->CAFile->bind(key: 'KV_SSLCAFILE')
      ->up()
   ->Pool
      ->Min->bind(key: 'KV_POOL_MIN', cast: Types::Integer)
      ->Max->bind(key: 'KV_POOL_MAX', cast: Types::Integer);

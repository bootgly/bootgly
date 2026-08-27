<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'kv')
   ->Enabled->bind(key: 'KV_ENABLED', default: true, cast: Types::Boolean)
   ->Driver->bind(key: 'KV_DRIVER', default: 'redis')
   ->Host->bind(key: 'KV_HOST', default: '127.0.0.1')
   ->Port->bind(key: 'KV_PORT', default: 6379, cast: Types::Integer)
   ->Database->bind(key: 'KV_DATABASE', default: '0')
   ->Password->bind(key: 'KV_PASS', default: '')
   ->Timeout->bind(key: 'KV_TIMEOUT', default: 30.0, cast: Types::Float)
   ->Secure
      ->Mode->bind(key: 'KV_SSLMODE', default: ADIConfig::SECURE_DISABLE)
      ->Verify->bind(key: 'KV_SSLVERIFY', default: null, cast: Types::Boolean)
      ->Peer->bind(key: 'KV_SSLPEER', default: null)
      ->CAFile->bind(key: 'KV_SSLCAFILE', default: null)
      ->up()
   ->Pool
      ->Min->bind(key: 'KV_POOL_MIN', default: 0, cast: Types::Integer)
      ->Max->bind(key: 'KV_POOL_MAX', default: 1, cast: Types::Integer);

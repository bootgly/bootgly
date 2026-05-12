<?php

use Bootgly\ADI\Databases\SQL\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'database')
   ->Enabled->bind(key: 'DB_ENABLED', default: true, cast: Types::Boolean)
   ->Default->bind(key: 'DB_CONNECTION', default: ADIConfig::DEFAULT_DRIVER)
   ->Connections
      ->PostgreSQL
         ->Driver->bind(key: '', default: ADIConfig::DEFAULT_DRIVER)
         ->Host->bind(key: 'DB_HOST', default: ADIConfig::DEFAULT_HOST)
         ->Port->bind(key: 'DB_PORT', default: ADIConfig::DEFAULT_PORT, cast: Types::Integer)
         ->Database->bind(key: 'DB_NAME', default: ADIConfig::DEFAULT_DATABASE)
         ->Username->bind(key: 'DB_USER', default: ADIConfig::DEFAULT_USERNAME)
         ->Password->bind(key: 'DB_PASS', default: ADIConfig::DEFAULT_PASSWORD)
         ->Timeout->bind(key: 'DB_TIMEOUT', default: ADIConfig::DEFAULT_TIMEOUT, cast: Types::Float)
         ->Statements->bind(key: 'DB_STATEMENTS', default: ADIConfig::DEFAULT_STATEMENTS, cast: Types::Integer)
         ->Secure
            ->Mode->bind(key: 'DB_SSLMODE', default: ADIConfig::DEFAULT_SECURE_MODE)
            ->Verify->bind(key: 'DB_SSLVERIFY', default: ADIConfig::DEFAULT_SECURE_MODE !== ADIConfig::SECURE_DISABLE, cast: Types::Boolean)
            ->Peer->bind(key: 'DB_SSLPEER', default: null)
            ->CAFile->bind(key: 'DB_SSLCAFILE', default: ADIConfig::DEFAULT_SECURE_CAFILE)
            ->up()
         ->Pool
            ->Min->bind(key: 'DB_POOL_MIN', default: ADIConfig::DEFAULT_POOL_MIN, cast: Types::Integer)
            ->Max->bind(key: 'DB_POOL_MAX', default: ADIConfig::DEFAULT_POOL_MAX, cast: Types::Integer);

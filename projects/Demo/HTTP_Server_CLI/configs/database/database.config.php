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
            ->Max->bind(key: 'DB_POOL_MAX', default: ADIConfig::DEFAULT_POOL_MAX, cast: Types::Integer)
            ->up()
         ->Routing
            ->Sticky->bind(key: 'DB_ROUTING_STICKY', default: ADIConfig::DEFAULT_ROUTING_STICKY, cast: Types::Float)
            ->up()
         ->Replicas
            ->Replica1
               ->Host->bind(key: 'DB_REPLICA_1_HOST', default: null)
               ->Port->bind(key: 'DB_REPLICA_1_PORT', default: null, cast: Types::Integer)
               ->Database->bind(key: 'DB_REPLICA_1_NAME', default: null)
               ->Username->bind(key: 'DB_REPLICA_1_USER', default: null)
               ->Password->bind(key: 'DB_REPLICA_1_PASS', default: null)
               ->Timeout->bind(key: 'DB_REPLICA_1_TIMEOUT', default: null, cast: Types::Float)
               ->Statements->bind(key: 'DB_REPLICA_1_STATEMENTS', default: null, cast: Types::Integer)
               ->Secure
                  ->Mode->bind(key: 'DB_REPLICA_1_SSLMODE', default: null)
                  ->Verify->bind(key: 'DB_REPLICA_1_SSLVERIFY', default: null, cast: Types::Boolean)
                  ->Peer->bind(key: 'DB_REPLICA_1_SSLPEER', default: null)
                  ->CAFile->bind(key: 'DB_REPLICA_1_SSLCAFILE', default: null)
                  ->up()
               ->Pool
                  ->Min->bind(key: 'DB_REPLICA_1_POOL_MIN', default: null, cast: Types::Integer)
                  ->Max->bind(key: 'DB_REPLICA_1_POOL_MAX', default: null, cast: Types::Integer)
                  ->up()
               ->up()
            ->Replica2
               ->Host->bind(key: 'DB_REPLICA_2_HOST', default: null)
               ->Port->bind(key: 'DB_REPLICA_2_PORT', default: null, cast: Types::Integer)
               ->Database->bind(key: 'DB_REPLICA_2_NAME', default: null)
               ->Username->bind(key: 'DB_REPLICA_2_USER', default: null)
               ->Password->bind(key: 'DB_REPLICA_2_PASS', default: null)
               ->Timeout->bind(key: 'DB_REPLICA_2_TIMEOUT', default: null, cast: Types::Float)
               ->Statements->bind(key: 'DB_REPLICA_2_STATEMENTS', default: null, cast: Types::Integer)
               ->Secure
                  ->Mode->bind(key: 'DB_REPLICA_2_SSLMODE', default: null)
                  ->Verify->bind(key: 'DB_REPLICA_2_SSLVERIFY', default: null, cast: Types::Boolean)
                  ->Peer->bind(key: 'DB_REPLICA_2_SSLPEER', default: null)
                  ->CAFile->bind(key: 'DB_REPLICA_2_SSLCAFILE', default: null)
                  ->up()
               ->Pool
                  ->Min->bind(key: 'DB_REPLICA_2_POOL_MIN', default: null, cast: Types::Integer)
                  ->Max->bind(key: 'DB_REPLICA_2_POOL_MAX', default: null, cast: Types::Integer);

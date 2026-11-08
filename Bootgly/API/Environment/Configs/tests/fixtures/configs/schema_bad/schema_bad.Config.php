<?php

use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Field;

return new Schema(scope: 'schema_bad', Fields: [
   'Port' => new Field(key: 'SCHEMA_BAD_PORT', default: 8080, cast: Types::Integer, Rules: [
      new Minimum(1),
      new Maximum(65535),
   ]),
   'Workers' => new Field(key: 'SCHEMA_BAD_WORKERS', default: 4, cast: Types::Integer),
   'Connections.Primary.Password' => new Field(key: 'SCHEMA_BAD_PASS', required: true),
])->build();

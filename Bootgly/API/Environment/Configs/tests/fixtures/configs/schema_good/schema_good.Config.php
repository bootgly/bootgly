<?php

use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Field;

return new Schema(scope: 'schema_good', Fields: [
   'Host' => new Field(key: 'SCHEMA_HOST', default: 'localhost'),
   'Port' => new Field(key: 'SCHEMA_PORT', default: 8080, cast: Types::Integer, Rules: [
      new Minimum(1),
      new Maximum(65535),
   ]),
   'Connections.Primary.Password' => new Field(key: 'SCHEMA_PASS', required: true),
])->build();

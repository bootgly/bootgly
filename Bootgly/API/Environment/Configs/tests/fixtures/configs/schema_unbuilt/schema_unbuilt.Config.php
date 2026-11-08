<?php

use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Field;

// ! A Schema returned without build(): the load must fail loudly
return new Schema(scope: 'schema_unbuilt', Fields: [
   'Password' => new Field(key: 'SCHEMA_UNBUILT_PASS', required: true),
]);

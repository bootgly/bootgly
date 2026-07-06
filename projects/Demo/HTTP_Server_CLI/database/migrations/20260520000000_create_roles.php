<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;


return new Migration(
   Up: function (Migrating $Schema) {
      return $Schema->create('roles', function (Blueprint $Table): void {
         $Table->add('id', Types::BigInteger)
            ->generate()
            ->constrain(Keys::Primary);
         $Table->add('name', Types::String)
            ->limit(80)
            ->constrain(Keys::Unique);
         $Table->add('created_at', Types::Timestamp)->default = new Expression('CURRENT_TIMESTAMP');
      });
   },
   Down: function (Migrating $Schema) {
      return $Schema->drop('roles');
   }
);
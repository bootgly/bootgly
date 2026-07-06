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
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;


return new Migration(
   Up: function (Migrating $Schema) {
      return [
         $Schema->create('user_roles', function (Blueprint $Table): void {
            $Table->add('user_id', Types::String)->limit(120);
            $Table->add('role_id', Types::BigInteger)->reference('roles');
            $Table->add('created_at', Types::Timestamp)->default = new Expression('CURRENT_TIMESTAMP');
         }),
         $Schema->index(
            'user_roles',
            ['user_id', 'role_id'],
            name: 'user_roles_user_role_unique',
            unique: true
         ),
      ];
   },
   Down: function (Migrating $Schema) {
      return [
         $Schema->unindex('user_roles', 'user_roles_user_role_unique'),
         $Schema->drop('user_roles'),
      ];
   }
);
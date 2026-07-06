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
         $Schema->create('role_permissions', function (Blueprint $Table): void {
            $Table->add('role_id', Types::BigInteger)->reference('roles');
            $Table->add('permission_id', Types::BigInteger)->reference('permissions');
            $Table->add('created_at', Types::Timestamp)->default = new Expression('CURRENT_TIMESTAMP');
         }),
         $Schema->index(
            'role_permissions',
            ['role_id', 'permission_id'],
            name: 'role_permissions_role_permission_unique',
            unique: true
         ),
      ];
   },
   Down: function (Migrating $Schema) {
      return [
         $Schema->unindex('role_permissions', 'role_permissions_role_permission_unique'),
         $Schema->drop('role_permissions'),
      ];
   }
);
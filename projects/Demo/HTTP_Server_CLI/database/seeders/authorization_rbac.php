<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;


return new Seeder(
   Run: function (SQL $Database, Seed $Seed): array {
      return [
         $Database->table(new Identifier('roles'))
            ->insert()
            ->set(new Identifier('id'), 1, 2)
            ->set(new Identifier('name'), 'admin', 'editor')
            ->upsert(new Identifier('id')),
         $Database->table(new Identifier('permissions'))
            ->insert()
            ->set(new Identifier('id'), 1, 2, 3, 4)
            ->set(new Identifier('name'), 'demo:read', 'demo:write', 'posts:update', 'posts:delete')
            ->upsert(new Identifier('id')),
         $Database->table(new Identifier('role_permissions'))
            ->insert()
            ->set(new Identifier('role_id'), 1, 1, 1, 1, 2, 2, 2)
            ->set(new Identifier('permission_id'), 1, 2, 3, 4, 1, 2, 3)
            ->upsert(new Identifier('role_id'), new Identifier('permission_id')),
         $Database->table(new Identifier('user_roles'))
            ->insert()
            ->set(new Identifier('user_id'), 'admin-user', 'demo-user')
            ->set(new Identifier('role_id'), 1, 2)
            ->upsert(new Identifier('user_id'), new Identifier('role_id')),
      ];
   }
);
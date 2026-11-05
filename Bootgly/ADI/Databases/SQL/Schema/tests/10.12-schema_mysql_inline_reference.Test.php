<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;


return new Test(
   description: 'Database: MySQL compiles an inline column reference as an enforced foreign key',
   test: function () {
      $Schema = new SQL(['driver' => 'mysql'])->structure();

      // # CREATE TABLE
      //   MySQL parses a `REFERENCES` clause inside a column definition and
      //   ignores it — no foreign key, no error, no warning — so orphan rows
      //   were accepted while the migration was recorded as applied. Only the
      //   table-level `CONSTRAINT … FOREIGN KEY` creates one: every inline
      //   reference compiles to it, after the column definitions and before
      //   the table-level references.
      $Query = $Schema->create('order_items', function (Blueprint $Table): void {
         $Table->add('id', Types::BigInteger)->generate()->constrain(Keys::Primary);
         $Table->add('order_id', Types::BigInteger)
            ->reference('orders')
            ->Reference?->delete(References::Cascade);
         $Table->add('product_id', Types::BigInteger)->reference('products', 'code', 'items_product_fk');
         $Table->add('quantity', Types::Integer);
         $Table->add('coupon_id', Types::BigInteger);
         $Table->reference('coupon_id', 'coupons')->update(References::Restrict);
      });

      yield assert(
         assertion: $Query->SQL === 'CREATE TABLE `order_items` ('
            . '`id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`order_id` BIGINT NOT NULL, '
            . '`product_id` BIGINT NOT NULL, '
            . '`quantity` INT NOT NULL, '
            . '`coupon_id` BIGINT NOT NULL, '
            . 'CONSTRAINT `order_items_order_id_orders_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`code`), '
            . 'CONSTRAINT `order_items_coupon_id_coupons_fk` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON UPDATE RESTRICT)',
         description: "An inline reference compiles to a named table-level constraint, found: {$Query->SQL}"
      );

      // # ALTER TABLE … ADD COLUMN
      //   The same clause on an added column was ignored too: the column's
      //   foreign key now follows it as its own `ADD CONSTRAINT` action.
      $Query = $Schema->alter('children', function (Blueprint $Table): void {
         $Table->add('parent_id', Types::BigInteger)->reference('parents')->nullable = true;
         $Table->add('label', Types::String)->limit(40);
      });

      yield assert(
         assertion: $Query->SQL === 'ALTER TABLE `children` '
            . 'ADD COLUMN `parent_id` BIGINT, '
            . 'ADD CONSTRAINT `children_parent_id_parents_fk` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`), '
            . 'ADD COLUMN `label` VARCHAR(40) NOT NULL',
         description: "An added column's inline reference follows it as an ADD CONSTRAINT, found: {$Query->SQL}"
      );

      // # Declared twice: inline and at the table level
      //   Both forms name the key `<table>_<column>_<target>_fk`, and MySQL
      //   keeps one namespace of foreign key names per database, so compiling
      //   both failed the statement with 1826. When the table-level one
      //   already carries every rule the inline one states, it is the one
      //   compiled.
      $Query = $Schema->create('members', function (Blueprint $Table): void {
         $Table->add('team_id', Types::BigInteger)
            ->reference('teams')
            ->Reference?->update(References::Restrict);
         $Table->reference('team_id', 'teams')
            ->delete(References::Cascade)
            ->update(References::Restrict);
      });

      yield assert(
         assertion: $Query->SQL === 'CREATE TABLE `members` (`team_id` BIGINT NOT NULL, '
            . 'CONSTRAINT `members_team_id_teams_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT)',
         description: "A key declared inline and at the table level compiles once, found: {$Query->SQL}"
      );

      $Query = $Schema->alter('members', function (Blueprint $Table): void {
         $Table->add('role_id', Types::BigInteger)->reference('roles')->nullable = true;
         $Table->reference('role_id', 'roles')->update(References::Cascade);
      });

      yield assert(
         assertion: $Query->SQL === 'ALTER TABLE `members` ADD COLUMN `role_id` BIGINT, '
            . 'ADD CONSTRAINT `members_role_id_roles_fk` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE',
         description: "An added column's key declared twice is added once, found: {$Query->SQL}"
      );

      // # Anything less than the same key is not folded
      //   A different name, source column, target table, target column or a
      //   rule the table-level one lacks makes it another key: both compile,
      //   and on a shared name the server refuses the clash out loud instead
      //   of a foreign key or a rule being dropped in silence.
      $variants = [
         'name' => [['owner_id', 'users', 'id', 'links_inline_fk'], ['owner_id', 'users', 'id', 'links_owner_fk'], null],
         'source' => [['owner_id', 'users', 'id', 'links_owner_fk'], ['editor_id', 'users', 'id', 'links_owner_fk'], null],
         'table' => [['owner_id', 'users', 'id', 'links_owner_fk'], ['owner_id', 'teams', 'id', 'links_owner_fk'], null],
         'column' => [['owner_id', 'users', 'id', 'links_owner_fk'], ['owner_id', 'users', 'code', 'links_owner_fk'], null],
         'rule' => [['owner_id', 'users', 'id', 'links_owner_fk'], ['owner_id', 'users', 'id', 'links_owner_fk'], References::Cascade],
      ];

      foreach ($variants as $differs => [$inline, $table, $rule]) {
         $Query = $Schema->create('links', function (Blueprint $Table) use ($inline, $table, $rule): void {
            $Table->add('owner_id', Types::BigInteger)->reference($inline[1], $inline[2], $inline[3])->Reference?->delete(
               $rule ?? References::NoAction
            );
            $Table->add('editor_id', Types::BigInteger);
            $Table->reference($table[0], $table[1], $table[2], $table[3])->delete(References::NoAction);
         });

         $inlineDelete = $rule === null ? 'NO ACTION' : 'CASCADE';
         $expected = 'CREATE TABLE `links` (`owner_id` BIGINT NOT NULL, `editor_id` BIGINT NOT NULL, '
            . "CONSTRAINT `{$inline[3]}` FOREIGN KEY (`{$inline[0]}`) REFERENCES `{$inline[1]}` (`{$inline[2]}`) ON DELETE {$inlineDelete}, "
            . "CONSTRAINT `{$table[3]}` FOREIGN KEY (`{$table[0]}`) REFERENCES `{$table[1]}` (`{$table[2]}`) ON DELETE NO ACTION)";

         yield assert(
            assertion: $Query->SQL === $expected,
            description: "A reference that differs in its {$differs} keeps both constraints, found: {$Query->SQL}"
         );
      }

      $Query = $Schema->create('links', function (Blueprint $Table): void {
         $Table->add('owner_id', Types::BigInteger)
            ->reference('users', 'id', 'links_owner_fk')
            ->Reference?->update(References::Cascade);
         $Table->reference('owner_id', 'users', 'id', 'links_owner_fk')->update(References::Restrict);
      });

      yield assert(
         assertion: $Query->SQL === 'CREATE TABLE `links` (`owner_id` BIGINT NOT NULL, '
            . 'CONSTRAINT `links_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE, '
            . 'CONSTRAINT `links_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT)',
         description: "A reference that differs in its update rule keeps both constraints, found: {$Query->SQL}"
      );

      // # A rule only the inline one states is a rule the table-level one lacks
      $lacks = [
         'delete' => static fn (Blueprint $Table) => $Table->add('owner_id', Types::BigInteger)
            ->reference('users', 'id', 'links_owner_fk')
            ->Reference?->delete(References::Cascade),
         'update' => static fn (Blueprint $Table) => $Table->add('owner_id', Types::BigInteger)
            ->reference('users', 'id', 'links_owner_fk')
            ->Reference?->update(References::Cascade),
      ];

      foreach ($lacks as $rule => $inline) {
         $Query = $Schema->create('links', function (Blueprint $Table) use ($inline): void {
            $inline($Table);
            $Table->reference('owner_id', 'users', 'id', 'links_owner_fk');
         });

         $action = strtoupper($rule);

         yield assert(
            assertion: $Query->SQL === 'CREATE TABLE `links` (`owner_id` BIGINT NOT NULL, '
               . "CONSTRAINT `links_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON {$action} CASCADE, "
               . 'CONSTRAINT `links_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`))',
            description: "An inline {$rule} rule the table-level reference lacks keeps both constraints, found: {$Query->SQL}"
         );
      }
   }
);

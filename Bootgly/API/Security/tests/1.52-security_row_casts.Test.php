<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\RowCasts;


use function assert;
use function get_object_vars;
use function json_encode;
use Closure;
use ReflectionClass;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\Users;


return new Test(
   description: 'Security: account rows and refresh records keep their scalar shapes (Users id through Casters, Tokens exact types)',
   test: function () {
      // # Users: the key column's driver shape (int or string) becomes a string id; anything else fails closed
      $Users = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
      Closure::bind(function (): void {
         $this->key = 'id';
         $this->identifier = 'email';
         $this->secret = 'password';
         $this->verified = 'email_verified_at';
      }, $Users, Users::class)();
      $map = Closure::bind(fn (Operation $Operation): null|array => $this->cast($Operation), $Users, Users::class);
      $row = static function (mixed $id, mixed $verified = null) use ($map): null|array {
         $Operation = new Operation(null, 'SELECT 1');
         $Operation->rows = [['id' => $id, 'email' => 'a@example.com', 'password' => 'hash', 'email_verified_at' => $verified]];

         return $map($Operation);
      };

      $shapes = [
         [42, '42'],
         ['42', '42'],
         ['018f6b5e-uuid', '018f6b5e-uuid'],
         [0, '0'],
         [-1, '-1'],
      ];
      foreach ($shapes as [$id, $expected]) {
         $Account = $row($id);

         yield assert(
            assertion: $Account !== null && $Account['id'] === $expected,
            description: 'A key ' . json_encode($id) . " maps to the id '{$expected}'"
         );
      }

      $refused = [true, false, null, [1], new stdClass];
      $open = [];
      foreach ($refused as $id) {
         if ($row($id) !== null) {
            $open[] = $id;
         }
      }
      yield assert(
         assertion: $open === [],
         description: 'A bool, null, array or object key fails closed (no account)'
      );

      yield assert(
         assertion: $row(1)['verified'] === false && $row(1, '2026-11-08 10:00:00')['verified'] === true,
         description: 'verified is the presence of the verification column'
      );

      // # Tokens: a refresh record is read with exact JSON types — no scalar is converted
      $Tokens = new ReflectionClass(Tokens::class)->newInstanceWithoutConstructor();
      $decode = Closure::bind(fn (string $value): null|array => $this->decode($value), $Tokens, Tokens::class);
      $build = Closure::bind(fn (string $refresh, array $Record): object => $this->cast($refresh, $Record), $Tokens, Tokens::class);

      $Record = $decode('{"subject":"u1","family":"f","claims":{"a":1},"issued":10,"expires":20}');
      $Token = $Record === null ? null : $build('r', $Record);
      yield assert(
         assertion: $Token !== null && get_object_vars($Token) === [
            'refresh' => 'r',
            'subject' => 'u1',
            'family' => 'f',
            'claims' => ['a' => 1],
            'issued' => 10,
            'expires' => 20,
         ],
         description: 'A well-typed record builds its Token'
      );

      $malformed = [
         '{"subject":"u1","family":"f","claims":{},"issued":"10","expires":20}',
         '{"subject":"u1","family":"f","claims":{},"issued":10.0,"expires":20}',
         '{"subject":"u1","family":"f","claims":{},"issued":99999999999999999999,"expires":20}',
         '{"subject":1,"family":"f","claims":{},"issued":10,"expires":20}',
         '{"subject":"u1","family":"f","claims":[1],"issued":10,"expires":20}',
         'not json',
      ];
      $accepted = [];
      foreach ($malformed as $json) {
         if ($decode($json) !== null) {
            $accepted[] = $json;
         }
      }
      yield assert(
         assertion: $accepted === [],
         description: 'A numeric string, float or bigint time, a non-string subject or list claims are refused, not converted — ' . json_encode($accepted)
      );
   }
);

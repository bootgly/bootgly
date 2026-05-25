<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Authorization;
use Bootgly\API\Security\Authorization\Abilities;
use Bootgly\API\Security\Authorization\Policy;
use Bootgly\API\Security\Identity;


return new Specification(
   description: 'Authorization: evaluate policies and abilities',
   test: function () {
      $Authorization = new Authorization;
      $Identity = new Identity(
         id: 'user-42',
         claims: ['role' => 'editor'],
         scopes: ['posts:view']
      );
      $Post = (object) ['owner' => 'user-42'];
      $OtherPost = (object) ['owner' => 'user-99'];

      $Policy = new class extends Policy {
         public function view (Identity $Identity, mixed $Resource = null): null|bool
         {
            return $Identity->check('posts:view');
         }

         public function update (Identity $Identity, mixed $Resource = null): null|bool
         {
            return $Resource->owner === $Identity->id;
         }

         public function delete (Identity $Identity, mixed $Resource = null): null|bool
         {
            return false;
         }
      };

      yield assert(
         assertion: $Authorization->authorize($Identity, $Policy, 'view', $Post) === true,
         description: 'policy allows exact granted view action'
      );

      yield assert(
         assertion: $Authorization->authorize($Identity, $Policy, 'update', $Post) === true,
         description: 'policy allows resource owner update action'
      );

      yield assert(
         assertion: $Authorization->authorize($Identity, $Policy, 'update', $OtherPost) === false,
         description: 'policy denies non-owner update action'
      );

      yield assert(
         assertion: $Authorization->authorize($Identity, $Policy, 'delete', $Post) === false,
         description: 'policy denies explicit false action'
      );

      $PublishingPolicy = new class extends Policy {
         public function publish (Identity $Identity, mixed $Resource = null): null|bool
         {
            return $Identity->check('posts:view');
         }
      };

      yield assert(
         assertion: $Authorization->authorize($Identity, $PublishingPolicy, 'publish', $Post) === true,
         description: 'policy allows custom public action methods'
      );

      yield assert(
         assertion: $Authorization->authorize($Identity, $Policy, 'publish', $Post) === false,
         description: 'unknown policy actions fail closed'
      );

      $Admin = new Identity(id: 'admin-1', claims: ['role' => 'admin']);
      $AdminPolicy = new class extends Policy {
         public function override (Identity $Identity, mixed $Resource = null): null|bool
         {
            return ($Identity->claims['role'] ?? null) === 'admin' ? true : null;
         }

         public function delete (Identity $Identity, mixed $Resource = null): null|bool
         {
            return false;
         }
      };

      yield assert(
         assertion: $Authorization->authorize($Admin, $AdminPolicy, 'delete', $Post) === true,
         description: 'policy override short-circuits action checks'
      );

      $Abilities = new Abilities;
      $Abilities->define('posts:update', function (Identity $Identity, object $Post): bool {
         return $Post->owner === $Identity->id;
      });

      yield assert(
         assertion: $Abilities->check($Identity, 'posts:update', $Post) === true,
         description: 'registered ability allows matching resource'
      );

      yield assert(
         assertion: $Abilities->check($Identity, 'posts:update', $OtherPost) === false,
         description: 'registered ability denies mismatched resource'
      );

      yield assert(
         assertion: $Abilities->check($Identity, 'posts:delete', $Post) === false,
         description: 'missing ability fails closed'
      );

      $failed = false;
      try {
         $Abilities->define('', function (): bool {
            return true;
         });
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'empty ability names are rejected'
      );
   }
);

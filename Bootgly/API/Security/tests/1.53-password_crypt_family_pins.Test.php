<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordCryptFamilyPins;


use function assert;
use function defined;
use function password_get_info;
use function str_starts_with;
use SensitiveParameter;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;


/**
 * Verifier that refuses every hash and records the ones it was asked for.
 */
final class Refuser implements Verifier
{
   // * Metadata
   /** @var array<int,string> */
   public private(set) array $asked = [];


   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked[] = $hash;

      return false;
   }
}


return new Test(
   description: 'Password: crypt-family hashes minted by other stacks ($2a$, $2b$, $6$) verify natively and upgrade through inspect(), with or without verifiers',
   skip: defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      // ! Published vectors, minted by other implementations: the OpenBSD
      //   bcrypt vector (`$2a$`, and the same digest under the `$2b$` tag
      //   newer stacks emit) and the SHA-512 crypt reference vector.
      //   Stock PHP names no algorithm for them, so a gate that skipped the
      //   native check for an unnamed hash would lock these accounts out
      $rows = [
         '$2a$' => ['$2a$05$CCCCCCCCCCCCCCCCCCCCC.E5YPO9kmyuRGyh0XouQYb4YMJKvyOeW', 'U*U'],
         '$2b$' => ['$2b$05$CCCCCCCCCCCCCCCCCCCCC.E5YPO9kmyuRGyh0XouQYb4YMJKvyOeW', 'U*U'],
         '$6$' => ['$6$saltstring$svn8UoSVapNtMuq1ukKS4tPQd8iKwSMHWjl/O817G3uBnIFNjnQJuesI68u4OTLiBFdcbYEdFCoEOfaS35inz1', 'Hello world!'],
      ];

      foreach ($rows as $format => [$hash, $secret]) {
         // ! Whether this PHP names an algorithm for the format (stock PHP
         //   does not; an extension that registers one makes it native)
         $unnamed = password_get_info($hash)['algo'] === null;

         foreach (['without verifiers', 'with a verifier'] as $setup) {
            $Refuser = new Refuser;
            $Password = $setup === 'with a verifier'
               ? new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Refuser])
               : new Password(memory: 19456, time: 2, threads: 1);

            yield assert(
               assertion: $Password->verify($secret, $hash) === true
                  && $Password->check($hash) === false
                  && $Refuser->asked === [],
               description: "{$format}, {$setup}: the right password verifies natively, the hash fails the current policy and no verifier is asked"
            );

            $Upgraded = $Password->inspect($secret, $hash);
            $fresh = (string) $Upgraded->hash;

            yield assert(
               assertion: $Upgraded->valid === true
                  && str_starts_with($fresh, '$argon2id$')
                  && $Password->check($fresh) === true
                  && $Password->verify($secret, $fresh) === true
                  && $Refuser->asked === [],
               description: "{$format}, {$setup}: inspect() passes carrying an argon2id hash that conforms and verifies"
            );

            $refused = $Password->verify("{$secret}!", $hash);
            $Failed = $Password->inspect("{$secret}!", $hash);

            yield assert(
               assertion: $refused === false
                  && $Failed->valid === false
                  && $Failed->hash === null,
               description: "{$format}, {$setup}: a wrong password does not verify and inspect() fails without a rehash"
            );

            // @ A native miss on an unnamed hash is the one case a verifier
            //   sees a crypt-family row: once per verification, the hash intact
            $expected = $setup === 'with a verifier' && $unnamed ? [$hash, $hash] : [];

            yield assert(
               assertion: $Refuser->asked === $expected,
               description: "{$format}, {$setup}: only the wrong-password misses on a hash PHP names no algorithm for reach the verifier"
            );
         }
      }
   }
);

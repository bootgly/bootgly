<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordVerifiersLegacy;


use function assert;
use function base64_encode;
use function defined;
use function hash_pbkdf2;
use function str_starts_with;
use SensitiveParameter;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;
use Bootgly\API\Security\Password\Verifiers\PBKDF2;


/**
 * A policy that counts the hashes it mints.
 */
final class Counter extends Password
{
   // * Metadata
   public private(set) int $hashes = 0;


   public function hash (#[SensitiveParameter] string $password): string
   {
      $this->hashes++;

      return parent::hash($password);
   }
}

/**
 * Verifier with a fixed answer that records the hashes it was asked for.
 */
final class Fixed implements Verifier
{
   // * Data
   private bool $answer;

   // * Metadata
   /** @var array<int,string> */
   public private(set) array $asked = [];


   public function __construct (bool $answer)
   {
      // * Data
      $this->answer = $answer;
   }

   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked[] = $hash;

      return $this->answer;
   }
}


return new Test(
   description: 'Password: a legacy hash verifies through its verifier, is rehashed once to the current policy and conforms afterwards',
   skip: defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      // ! A PBKDF2-SHA256 row as another stack stored it
      $secret = 'correct horse battery staple';
      $salt = 'sixteen-byte-key';
      $key = hash_pbkdf2('sha256', $secret, $salt, 1000, 32, true);
      $legacy = 'v1:1000:' . base64_encode($salt) . ':' . base64_encode($key);
      $Password = new Counter(memory: 19456, time: 2, threads: 1, Verifiers: [new PBKDF2(prefix: 'v1:')]);

      yield assert(
         assertion: $Password->verify($secret, $legacy) === true
            && $Password->check($legacy) === false
            && $Password->hashes === 0,
         description: 'the right password verifies against the legacy hash, which never conforms to the current policy'
      );

      // @ The upgrade: one hash minted, argon2id, conforming
      $Upgraded = $Password->inspect($secret, $legacy);
      $fresh = (string) $Upgraded->hash;

      yield assert(
         assertion: $Upgraded->valid === true
            && $Password->hashes === 1
            && str_starts_with($fresh, '$argon2id$')
            && $Password->check($fresh) === true
            && $Password->verify($secret, $fresh) === true,
         description: 'inspect() passes the legacy hash carrying one fresh argon2id hash that conforms and verifies'
      );

      $Settled = $Password->inspect($secret, $fresh);

      yield assert(
         assertion: $Settled->valid === true
            && $Settled->hash === null
            && $Password->hashes === 1,
         description: 'the upgraded hash is conformant afterwards: inspect() passes it without another rehash'
      );

      // @ A wrong password: no pass, no rehash
      $Failed = $Password->inspect("{$secret}!", $legacy);

      yield assert(
         assertion: $Password->verify("{$secret}!", $legacy) === false
            && $Failed->valid === false
            && $Failed->hash === null
            && $Password->hashes === 1,
         description: 'a wrong password against the legacy hash fails and mints nothing'
      );

      // @ Without the verifier the row is unreadable, as before
      $Plain = new Counter(memory: 19456, time: 2, threads: 1);
      $Unread = $Plain->inspect($secret, $legacy);

      yield assert(
         assertion: $Plain->verify($secret, $legacy) === false
            && $Unread->valid === false
            && $Unread->hash === null
            && $Plain->hashes === 0,
         description: 'without a verifier the legacy hash never verifies'
      );

      // @ Order: a refusal passes the hash on, an acceptance ends the search
      $Refusing = new Fixed(false);
      $Accepting = new Fixed(true);
      $Unasked = new Fixed(true);
      $Chain = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Refusing, $Accepting, $Unasked]);

      yield assert(
         assertion: $Chain->verify($secret, $legacy) === true
            && $Refusing->asked === [$legacy]
            && $Accepting->asked === [$legacy]
            && $Unasked->asked === [],
         description: 'a verifier that refuses passes the hash to the next one; the one that accepts ends the search'
      );

      $Refusing = new Fixed(false);
      $Other = new Fixed(false);
      $Chain = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Refusing, $Other]);

      yield assert(
         assertion: $Chain->verify($secret, $legacy) === false
            && $Refusing->asked === [$legacy]
            && $Other->asked === [$legacy],
         description: 'when every verifier refuses, each was asked once and the verification fails'
      );
   }
);

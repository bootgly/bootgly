<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordVerifierThrowing;


use const PASSWORD_BCRYPT;
use function assert;
use function count;
use function defined;
use function ini_set;
use function password_hash;
use function print_r;
use function str_contains;
use Error;
use RuntimeException;
use SensitiveParameter;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;


/**
 * Verifier that throws what it was given, counting how often it was asked.
 */
final class Thrower implements Verifier
{
   // * Data
   private Throwable $Throwable;

   // * Metadata
   public private(set) int $asked = 0;


   public function __construct (Throwable $Throwable)
   {
      // * Data
      $this->Throwable = $Throwable;
   }

   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked++;

      throw $this->Throwable;
   }
}

/**
 * Verifier that accepts every hash, counting how often it was asked.
 */
final class Acceptor implements Verifier
{
   // * Metadata
   public private(set) int $asked = 0;


   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked++;

      return true;
   }
}


return new Test(
   description: 'Password: a verifier that throws fails the verification and is reported once — never a pass, and no other verifier is asked',
   skip: defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      $secret = 'correct horse battery staple';
      $legacy = 'legacy:not-a-native-format';

      // ! The reporters are process-wide: capture on a private list and
      //   restore the previous one whatever happens below
      $previousReporters = Throwables::$reporters;
      Throwables::$reporters = [];
      $reported = [];
      Throwables::$reporters[] = function (Throwable $Throwable, array $context) use (&$reported): void {
         $reported[] = [$Throwable, $context];
      };

      try {
         // # An exception: false, reported once, the next verifier not asked
         $Outage = new RuntimeException('verifier backend is down');
         $Thrower = new Thrower($Outage);
         $Acceptor = new Acceptor;
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Thrower, $Acceptor]);
         $escaped = null;
         $verified = null;
         try {
            $verified = $Password->verify($secret, $legacy);
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable;
         }

         yield assert(
            assertion: $verified === false && $escaped === null,
            description: 'a verifier that throws makes the verification answer false — the throwable does not escape and is never a pass'
         );

         yield assert(
            assertion: count($reported) === 1
               && $reported[0][0] === $Outage
               && $reported[0][1] === ['origin' => 'password', 'verifier' => Thrower::class],
            description: 'the throwable is reported once through Throwables::notify() with the verifier that raised it'
         );

         yield assert(
            assertion: $Thrower->asked === 1 && $Acceptor->asked === 0,
            description: 'the remaining verifiers are not asked for that hash'
         );

         // # inspect(): a failed verification, no rehash
         $reported = [];
         $Thrower = new Thrower(new RuntimeException('verifier backend is down'));
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Thrower, new Acceptor]);
         $Inspected = $Password->inspect($secret, $legacy);

         yield assert(
            assertion: $Inspected->valid === false
               && $Inspected->hash === null
               && count($reported) === 1,
            description: 'inspect() fails without a rehash when a verifier throws, and the throwable is reported'
         );

         // # An Error is contained the same way
         $reported = [];
         $Fault = new Error('verifier bug');
         $Thrower = new Thrower($Fault);
         $Acceptor = new Acceptor;
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Thrower, $Acceptor]);

         yield assert(
            assertion: $Password->verify($secret, $legacy) === false
               && count($reported) === 1
               && $reported[0][0] === $Fault
               && $Acceptor->asked === 0,
            description: 'an Error raised by a verifier fails the verification and is reported too'
         );

         // # An anonymous verifier is named by its type: no path, no NUL byte
         $reported = [];
         $Anonymous = new class implements Verifier {
            public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
            {
               throw new RuntimeException('anonymous verifier is down');
            }
         };
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Anonymous]);
         $verified = $Password->verify($secret, $legacy);
         $context = $reported[0][1] ?? [];

         yield assert(
            assertion: $verified === false
               && count($reported) === 1
               && $context === ['origin' => 'password', 'verifier' => 'Bootgly\\API\\Security\\Password\\Verifier@anonymous'],
            description: 'an anonymous verifier that throws is reported under its type name, without a file path'
         );

         // # The reported trace carries neither the password nor the stored hash
         // ! A throwable raised below the frames under test, with arguments
         //   recorded in traces whatever the host's ini says (restored below)
         $Probe = new class implements Verifier {
            public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
            {
               throw new RuntimeException('probe');
            }
         };
         $reported = [];
         $ignored = ini_set('zend.exception_ignore_args', '0');
         try {
            new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Probe])->inspect($secret, $legacy);
         }
         finally {
            if ($ignored !== false) {
               ini_set('zend.exception_ignore_args', $ignored);
            }
         }
         // ! The frames under test: the verifier's verify(), Password::verify()
         //   and Password::inspect() — each must carry its two arguments
         $frames = [];
         $exposed = '';
         foreach (isSet($reported[0]) ? $reported[0][0]->getTrace() : [] as $frame) {
            $function = $frame['function'];

            if (isSet($frame['class']) && ($function === 'verify' || $function === 'inspect')) {
               $arguments = $frame['args'] ?? [];
               $frames[] = "{$function}:" . count($arguments);
               $exposed .= print_r($arguments, true);
            }
         }

         yield assert(
            assertion: $frames === ['verify:2', 'verify:2', 'inspect:2']
               && str_contains($exposed, 'SensitiveParameterValue')
               && str_contains($exposed, $secret) === false
               && str_contains($exposed, $legacy) === false,
            description: 'the trace of the reported throwable records the arguments of the verifier, verify() and inspect() frames, and none of them shows the password or the stored hash'
         );

         // # An acceptance before the thrower still wins; a native hash never reaches it
         $reported = [];
         $Thrower = new Thrower(new RuntimeException('never reached'));
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [new Acceptor, $Thrower]);
         $bcrypt = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]);
         $Guarded = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Thrower]);

         yield assert(
            assertion: $Password->verify($secret, $legacy) === true
               && $Guarded->verify($secret, $bcrypt) === true
               && $Guarded->verify("{$secret}!", $bcrypt) === false
               && $Guarded->verify($secret, '') === false
               && $Thrower->asked === 0
               && $reported === [],
            description: 'a verifier that would throw is not asked after an acceptance, for a native hash or for an empty one — nothing is reported'
         );
      }
      finally {
         Throwables::$reporters = $previousReporters;
      }
   }
);

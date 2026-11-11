<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordVerifiersSurface;


use const T_CURLY_OPEN;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_FUNCTION;
use const T_IS_EQUAL;
use const T_IS_IDENTICAL;
use const T_IS_NOT_EQUAL;
use const T_IS_NOT_IDENTICAL;
use const T_RETURN;
use const T_STRING;
use const T_USE;
use const T_VARIABLE;
use const T_WHITESPACE;
use function assert;
use function base64_encode;
use function count;
use function defined;
use function file_get_contents;
use function hash_pbkdf2;
use function implode;
use function in_array;
use function is_array;
use function json_encode;
use function token_get_all;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use SensitiveParameter;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;
use Bootgly\API\Security\Password\Verifiers\PBKDF2;


/**
 * An application policy written before the verifiers existed: it already
 * owns a public `$Verifiers` and a public `attach()` of another shape.
 */
class Squatter extends Password
{
   // * Data
   /** @var array<int,string> */
   public array $Verifiers = ['mine'];


   public function attach (string $device): string
   {
      return "attached {$device}";
   }
}

/**
 * An application policy whose constructor never calls the parent's.
 */
class Detached extends Password
{
   public function __construct ()
   {
      // ...no parent::__construct() on purpose
   }
}


return new Test(
   description: 'Password verifiers: the public surface stays additive, passwords and hashes stay out of traces and the PBKDF2 comparison is constant-time',
   skip: defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      // # Sensitive parameters — the password AND the stored hash
      $sensitive = [
         [Password::class, 'hash', ['password']],
         [Password::class, 'verify', ['password', 'hash']],
         [Password::class, 'inspect', ['password', 'hash']],
         [Verifier::class, 'verify', ['password', 'hash']],
         [PBKDF2::class, 'verify', ['password', 'hash']],
      ];
      $legs = [];
      foreach ($sensitive as [$class, $method, $names]) {
         $Method = new ReflectionMethod($class, $method);
         $marked = [];
         foreach ($Method->getParameters() as $Parameter) {
            if ($Parameter->getAttributes(SensitiveParameter::class) !== []) {
               $marked[] = $Parameter->getName();
            }
         }

         if ($marked !== $names) {
            $legs[] = "{$class}::{$method}";
         }
      }
      $failed = implode(', ', $legs);

      yield assert(
         assertion: $legs === [],
         description: "every password and stored-hash parameter is marked #[SensitiveParameter] — unmarked: [{$failed}]"
      );

      // # Additive surface: what the change added to Password is private,
      //   the class is not final and the four signatures did not move
      $Class = new ReflectionClass(Password::class);
      $Constructor = $Class->getConstructor();
      $Last = $Constructor?->getParameters()[3] ?? null;
      $signatures = [];
      foreach (['hash', 'verify', 'check', 'inspect'] as $method) {
         $Method = new ReflectionMethod(Password::class, $method);
         $Return = $Method->getReturnType();
         $parameters = [];
         foreach ($Method->getParameters() as $Parameter) {
            $Type = $Parameter->getType();
            $parameters[] = ($Type instanceof ReflectionNamedType ? $Type->getName() : '?') . " \${$Parameter->getName()}";
         }
         $signatures[$method] = implode(', ', $parameters) . ': ' . ($Return instanceof ReflectionNamedType ? $Return->getName() : '?');
      }

      yield assert(
         assertion: $Class->isFinal() === false
            && new ReflectionProperty(Password::class, 'Verifiers')->isPrivate() === true
            && new ReflectionMethod(Password::class, 'attach')->isPrivate() === true
            && $Constructor !== null
            && $Constructor->getNumberOfRequiredParameters() === 0
            && count($Constructor->getParameters()) === 4
            && $Last?->getName() === 'Verifiers'
            && $Last->isOptional() === true
            && $signatures === [
               'hash' => 'string $password: string',
               'verify' => 'string $password, string $hash: bool',
               'check' => 'string $hash: bool',
               'inspect' => 'string $password, string $hash: Bootgly\API\Security\Password\Verification',
            ],
         description: 'Password is not final, its new members are private, the verifier list is a trailing optional argument and hash/verify/check/inspect keep their signatures'
      );

      // # A subclass that already owns the names keeps working
      $secret = 'correct horse battery staple';
      $salt = 'sixteen-byte-key';
      $legacy = 'v1:1000:' . base64_encode($salt) . ':' . base64_encode(hash_pbkdf2('sha256', $secret, $salt, 1000, 32, true));
      $Squatter = new Squatter(memory: 19456, time: 2, threads: 1, Verifiers: [new PBKDF2(prefix: 'v1:', ceiling: 1000)]);

      yield assert(
         assertion: $Squatter->Verifiers === ['mine']
            && $Squatter->attach('disk') === 'attached disk'
            && $Squatter->verify($secret, $legacy) === true
            && $Squatter->verify("{$secret}!", $legacy) === false,
         description: 'a subclass declaring its own public $Verifiers and attach() compiles, keeps them and still verifies through the configured verifier'
      );

      // # A subclass whose constructor skips the parent still verifies natively
      $Detached = new Detached;
      $native = new Password(memory: 19456, time: 2, threads: 1)->hash($secret);

      yield assert(
         assertion: $Detached->verify($secret, $native) === true
            && $Detached->verify("{$secret}!", $native) === false
            && $Detached->verify($secret, $legacy) === false
            && $Detached->verify($secret, '') === false,
         description: 'a subclass that never ran the parent constructor verifies native hashes and refuses a legacy one without an error'
      );

      // # Constant-time comparison, pinned on the tokens of PBKDF2::verify():
      //   its last statement returns the global hash_equals() over the
      //   stored and the derived key; the key is derived once; the stored key
      //   is touched only by its decoding, its null and length checks and
      //   that comparison, the derived one only by its assignment and that
      //   comparison; and no other equality operator sits beside either. A
      //   refactor of the method has to restate this pin
      $File = new ReflectionClass(PBKDF2::class)->getFileName();
      $tokens = token_get_all((string) file_get_contents((string) $File));
      $body = [];
      $inside = false;
      $depth = 0;
      foreach ($tokens as $index => $token) {
         // ? The `verify` method: T_FUNCTION, whitespace, T_STRING verify
         if (
            $inside === false
            && is_array($token)
            && $token[0] === T_FUNCTION
            && is_array($tokens[$index + 2] ?? null)
            && $tokens[$index + 2][0] === T_STRING
            && $tokens[$index + 2][1] === 'verify'
         ) {
            $inside = true;
            continue;
         }
         if ($inside === false) {
            continue;
         }

         if ($token === '{' || (is_array($token) && ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
            $depth++;
         }
         elseif ($token === '}') {
            $depth--;
            if ($depth === 0) {
               break;
            }
         }
         if ($depth > 0 && (is_array($token) === false || $token[0] !== T_WHITESPACE)) {
            $body[] = is_array($token) ? $token : [0, $token];
         }
      }
      // ! The last statement of the body and every use of `$derived`
      $tail = [];
      foreach ($body as $index => $token) {
         if ($token[0] === T_RETURN) {
            $tail = [];
         }
         $tail[] = $token[1];
      }
      $uses = ['$derived' => 0, '$expected' => 0, 'hash_pbkdf2' => 0];
      $compared = 0;
      foreach ($body as $index => $token) {
         if (($token[0] === T_VARIABLE || $token[0] === T_STRING) && isSet($uses[$token[1]])) {
            $uses[$token[1]]++;
         }
         // ? An equality operator with a key on either side — the null
         //   check of the decoded key is the one comparison allowed
         if (in_array($token[0], [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL], true)) {
            $left = $body[$index - 1][1] ?? '';
            $right = $body[$index + 1][1] ?? '';
            $keyed = $left === '$expected' || $left === '$derived' || $right === '$expected' || $right === '$derived';

            if ($keyed && ($left !== '$expected' || $right !== 'null' || $token[0] !== T_IS_IDENTICAL)) {
               $compared++;
            }
         }
      }
      // ! The function is PHP's own: imported, and not declared in the file
      $imported = false;
      $declared = false;
      foreach ($tokens as $index => $token) {
         if (is_array($token) === false || $token[0] !== T_STRING || $token[1] !== 'hash_equals') {
            continue;
         }
         $before = [];
         for ($back = $index - 1; $back >= 0 && count($before) < 2; $back--) {
            if (is_array($tokens[$back]) && $tokens[$back][0] === T_WHITESPACE) {
               continue;
            }
            $before[] = is_array($tokens[$back]) ? $tokens[$back][0] : $tokens[$back];
         }
         if ($before === [T_FUNCTION, T_USE]) {
            $imported = true;
         }
         elseif (($before[0] ?? null) === T_FUNCTION) {
            $declared = true;
         }
      }
      $statement = implode(' ', $tail);
      $evidence = json_encode($uses);

      yield assert(
         assertion: $statement === 'return hash_equals ( $expected , $derived ) ;'
            && $uses === ['$derived' => 2, '$expected' => 4, 'hash_pbkdf2' => 1]
            && $compared === 0
            && $imported === true
            && $declared === false,
         description: "PBKDF2::verify() derives the key once and ends by returning PHP's hash_equals() over the stored and the derived key, with no other comparison of either — last statement: [{$statement}], uses: {$evidence}, equality operators beside a key: {$compared}"
      );
   }
);

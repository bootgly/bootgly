<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_STRING;
use const T_WHITESPACE;
use function assert;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function strtolower;
use function token_get_all;
use function trim;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers;


/**
 * The WPI scaffold demotes to the account the kit image ships, and every
 * shipped project that names one names the same; the server side is pinned
 * here only as the constant (`Servers::RUNTIME_USER`) — the root-launch
 * default that reads it, its container gate and its notice run as root and
 * have no spec yet (`I-28`).
 */
return new Test(
   description: 'the WPI scaffold and the server default agree on the `bootgly` runtime identity',
   test: function () {
      // ! Every value bound to `<argument>:` as a named argument, read from the
      //   token stream: a string literal as itself, `null` as null, anything
      //   else (a variable, a constant, a call) as '?' — nothing escapes the scan
      $named = static function (string $source, string $argument): array {
         $tokens = token_get_all($source);
         $count = count($tokens);
         $values = [];
         for ($i = 0; $i < $count; $i++) {
            $Token = $tokens[$i];
            if (is_array($Token) === false || $Token[0] !== T_STRING || $Token[1] !== $argument) {
               continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
               $j++;
            }
            if (($tokens[$j] ?? null) !== ':') {
               continue;
            }
            $k = $j + 1;
            while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
               $k++;
            }
            $Value = $tokens[$k] ?? null;
            if (is_array($Value) && $Value[0] === T_CONSTANT_ENCAPSED_STRING) {
               $values[] = trim($Value[1], "'\"");
            }
            elseif (is_array($Value) && $Value[0] === T_STRING && strtolower($Value[1]) === 'null') {
               $values[] = null;
            }
            else {
               $values[] = '?';
            }
         }

         return $values;
      };
      $literals = static function (string $source): array {
         $strings = [];
         foreach (token_get_all($source) as $Token) {
            if (is_array($Token) && $Token[0] === T_CONSTANT_ENCAPSED_STRING) {
               $strings[] = trim($Token[1], "'\"");
            }
         }

         return $strings;
      };

      // @ The scaffold: `user:` and `group:` each bound once, to the account
      $stub = (string) file_get_contents(BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs/WPI/__LEAF__.Project.php');

      yield assert(
         assertion: $named($stub, 'user') === [Servers::RUNTIME_USER] && $named($stub, 'group') === [Servers::RUNTIME_USER],
         description: 'the scaffold binds `user:` and `group:` to `' . Servers::RUNTIME_USER . '`, once each'
      );
      yield assert(
         assertion: in_array('debian', $literals($stub), true) === false,
         description: 'no `debian` literal survives in the scaffold'
      );

      // @ Every shipped project that binds a user names the same account —
      //   `null` (no demotion) is the one other value allowed
      $strays = [];
      $files = [
         ...glob(BOOTGLY_ROOT_BASE . '/projects/*/*.Project.php') ?: [],
         ...glob(BOOTGLY_ROOT_BASE . '/projects/*/*/*.Project.php') ?: [],
      ];
      foreach ($files as $file) {
         foreach ($named((string) file_get_contents($file), 'user') as $value) {
            if ($value !== null && $value !== Servers::RUNTIME_USER) {
               $strays[] = basename($file) . ' → ' . $value;
            }
         }
      }

      yield assert(
         assertion: $strays === [],
         description: 'no shipped project demotes to another account — ' . implode(', ', $strays)
      );

      // @ The server side of the contract, on the interface every server implements
      yield assert(
         assertion: Servers::RUNTIME_USER === 'bootgly',
         description: 'Servers::RUNTIME_USER is `bootgly` — the account the kit image creates'
      );
   }
);

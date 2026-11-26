<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\Sources;


use const PHP_BINARY;
use const T_FUNCTION;
use const T_STATIC;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;
use function array_unique;
use function assert;
use function count;
use function fclose;
use function file_get_contents;
use function function_exists;
use function in_array;
use function is_array;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function realpath;
use function serialize;
use function str_starts_with;
use function stream_get_contents;
use function token_get_all;
use function unserialize;
use Closure;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Resolver: the real sources — getaddrinfo with ext-sockets, the fallback without it',
   test: function () {
      $file = (string) realpath(__DIR__ . '/../../Resolver.php');

      // ! Fallback stand-ins that record whether they were asked
      $asked = 0;
      $Record = static function () use (&$asked): Closure {
         return static function () use (&$asked): array {
            $asked++;

            return ['192.0.2.1'];
         };
      };
      $Loopback = static function (null|array $addresses): bool {
         if ($addresses === null || $addresses === []) {
            return false;
         }
         foreach ($addresses as $address) {
            if (in_array($address, ['::1', '127.0.0.1'], true) === false) {
               return false;
            }
         }

         return count($addresses) === count(array_unique($addresses));
      };

      // @ With ext-sockets, `null` builds the real getaddrinfo — the fallback is never asked
      $sockets = function_exists('socket_addrinfo_lookup');
      $Resolver = new Resolver(null, $Record(), $Record());
      yield assert(
         assertion: $sockets === false || ($Loopback($Resolver->resolve('localhost')) && $asked === 0),
         description: 'with ext-sockets, the default lookup is getaddrinfo: localhost resolves to loopback, no fallback asked'
      );
      yield assert(
         assertion: $Loopback((new Resolver)->resolve('localhost')),
         description: 'the default resolver resolves localhost to its loopback addresses'
      );

      // @ The real IPv4 list without ext-sockets — localhost never reaches DNS
      $IPv4 = (new Resolver(false))->resolve('localhost');
      $loopbacks = $IPv4 !== null && $IPv4 !== [] && count($IPv4) === count(array_unique($IPv4));
      foreach ($IPv4 ?? [] as $address) {
         $loopbacks = $loopbacks && str_starts_with($address, '127.');
      }
      yield assert(
         assertion: $loopbacks,
         description: 'without getaddrinfo, localhost resolves through the hosts file alone (IPv4 loopback addresses)'
      );

      // @ A resolver of the system's resolver serializes, and its copy still resolves
      $Copy = unserialize(serialize(new Resolver));
      yield assert(
         assertion: $Copy instanceof Resolver && $Loopback($Copy->resolve('localhost')),
         description: 'the default resolver serializes and its copy still resolves'
      );

      // @ Without ext-sockets, `null` falls back — in a process where getaddrinfo is disabled
      $code = <<<'PHP'
require $argv[1];
$asked = [];
$Resolver = new Bootgly\ABI\IO\Resolver(
   null,
   static function (string $host) use (&$asked): array { $asked[] = "list:{$host}"; return ['192.0.2.1']; },
   static function (string $name) use (&$asked): array { $asked[] = "query:{$name}"; return [['ipv6' => '2001:db8::1']]; },
);
echo json_encode(['addresses' => $Resolver->resolve('Example.Test'), 'asked' => $asked, 'sockets' => function_exists('socket_addrinfo_lookup')]);
PHP;
      $Process = proc_open(
         [PHP_BINARY, '-d', 'disable_functions=socket_addrinfo_lookup', '-r', $code, $file],
         [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $pipes
      );
      $output = '';
      $errors = '';
      if (is_resource($Process)) {
         $output = (string) stream_get_contents($pipes[1]);
         $errors = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         proc_close($Process);
      }
      $child = json_decode($output, true);
      yield assert(
         assertion: is_array($child)
            && ($child['sockets'] ?? null) === false
            && ($child['addresses'] ?? null) === ['192.0.2.1', '2001:db8::1']
            && ($child['asked'] ?? null) === ['list:Example.Test', 'query:example.test.'],
         description: "without ext-sockets, the default resolver asks the IPv4 list, then the absolute AAAA name {$errors}"
      );

      // @ `SOCK_STREAM` (an ext-sockets constant) is read only inside the anonymous getaddrinfo closure
      $Tokens = token_get_all((string) file_get_contents($file));
      $reads = 0;
      $inside = 0;
      foreach ($Tokens as $index => $Token) {
         if (is_array($Token) === false || $Token[0] !== T_STRING || $Token[1] !== 'SOCK_STREAM') {
            continue;
         }
         // ? The `use const` import
         $previous = $index - 1;
         while ($previous >= 0 && is_array($Tokens[$previous]) && $Tokens[$previous][0] === T_WHITESPACE) {
            $previous--;
         }
         if (is_array($Tokens[$previous - 2] ?? null) && $Tokens[$previous - 2][0] === T_USE) {
            continue;
         }
         $reads++;
         // ! The innermost block that encloses the read
         $depth = 0;
         for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $Token = $Tokens[$cursor];
            if ($Token === '}') {
               $depth++;
            }
            else if ($Token === '{' || (is_array($Token) && in_array($Token[1], ['{', '${'], true))) {
               if ($depth === 0) {
                  break;
               }
               $depth--;
            }
         }
         // ! Its header: `static function (`
         for ($cursor--; $cursor >= 0; $cursor--) {
            if (is_array($Tokens[$cursor]) && $Tokens[$cursor][0] === T_FUNCTION) {
               $before = $cursor - 1;
               $after = $cursor + 1;
               while (is_array($Tokens[$before]) && $Tokens[$before][0] === T_WHITESPACE) {
                  $before--;
               }
               while (is_array($Tokens[$after]) && $Tokens[$after][0] === T_WHITESPACE) {
                  $after++;
               }
               if (is_array($Tokens[$before]) && $Tokens[$before][0] === T_STATIC && $Tokens[$after] === '(') {
                  $inside++;
               }
               break;
            }
         }
      }
      yield assert(
         assertion: $reads === 1 && $inside === 1,
         description: 'SOCK_STREAM is read once, inside the getaddrinfo closure (a host without ext-sockets never evaluates it)'
      );
   }
);

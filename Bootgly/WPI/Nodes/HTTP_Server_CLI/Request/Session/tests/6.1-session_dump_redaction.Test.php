<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Tests;


use function assert;
use function bin2hex;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function random_bytes;
use function serialize;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handling;


/**
 * The session ID is the bearer credential of a session: whoever holds it is
 * the user. A debug dump of a Session masks it, while the session data and
 * the state flags still show — and the dump never changes the live ID.
 */
return new Test(
   description: 'Session: debug dumps mask the session ID and keep the session data visible',
   test: function () {
      // ! SYNTH session ID — random hex, as the handlers require
      $id = bin2hex(random_bytes(20));
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! Each debug channel
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! A handler that persists nothing and loads one non-secret entry
      $Stub = new class implements Handling {
         public function read (string $sessionId): string
         {
            return serialize(['theme' => 'visible-theme-6.1']);
         }

         public function write (string $sessionId, string $sessionData): bool
         {
            return true;
         }

         public function touch (string $sessionId): bool
         {
            return true;
         }

         public function destroy (string $sessionId): bool
         {
            return true;
         }

         public function purge (int $maxLifetime): bool
         {
            return true;
         }
      };
      $previous = Handler::$instance;
      Handler::$instance = $Stub;
      try {
         $Session = new Session($id);

         // @@ Every channel
         $leaks = [];
         $missing = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Session);

            if (str_contains($output, $id) === true) {
               $leaks[] = $channel;
            }
            foreach (['visible-theme-6.1', 'loaded', Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} via {$channel}";
               }
            }
         }
         $leaked = implode(', ', $leaks);
         $absent = implode(', ', $missing);
         yield assert(
            assertion: $leaks === [] && $missing === [],
            description: "The session ID reaches no debug channel, while the session data and the flags show (leaked via: [{$leaked}], missing: [{$absent}])"
         );

         // @ Dumping never writes
         yield assert(
            assertion: $Session->id === $id && $Session->get('theme') === 'visible-theme-6.1',
            description: 'The live session ID and data are untouched by the dumps'
         );

         // @ The one way: the trait's __debugInfo, #[Secret] on the stored ID only
         $Own = new ReflectionMethod($Session, '__debugInfo');
         $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
         $marked = [];
         $misplaced = [];
         foreach ((new ReflectionClass(Session::class))->getProperties() as $Property) {
            if ($Property->getAttributes(Secret::class) === []) {
               continue;
            }

            $marked[] = $Property->name;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $misplaced[] = $Property->name;
            }
         }
         yield assert(
            assertion: $Own->getFileName() === $Trait->getFileName()
               && $Own->getStartLine() === $Trait->getStartLine()
               && $marked === ['id']
               && $misplaced === [],
            description: 'Session dumps through the Redactable trait, #[Secret] on the stored ID only'
         );

         // ! The destructor runs against the stub
         $Session = null;
      }
      finally {
         Handler::$instance = $previous;
      }
   }
);

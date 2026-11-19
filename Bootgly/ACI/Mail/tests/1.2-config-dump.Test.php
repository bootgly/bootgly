<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Mail;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Mail\Config: debug dumps mask the password and the token, also through the Mail facade and the SMTP client',
   test: function () {
      // ! SYNTH credentials — never real ones
      $password = 'SYNTH-MAIL-PASSWORD-1.2';
      $token = 'SYNTH-MAIL-TOKEN-1.2';
      $Dumper = new Dumper('plain');
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

      // ! The holder, and the objects that nest it
      $Mail = new Mail([
         'host' => 'smtp.example.invalid',
         'username' => 'visible-user@example.invalid',
         'password' => $password,
         'token' => $token
      ]);
      $Holders = [
         'Config' => $Mail->Config,
         'Mail' => $Mail,
         'SMTP_Client' => new SMTP_Client($Mail->Config)
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach (['password' => $password, 'token' => $token] as $name => $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$name} in {$holder} via {$channel}";
               }
            }
            foreach (['smtp.example.invalid', 'visible-user@example.invalid', Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No credential reaches var_dump, print_r, debug_zval_dump or Dumper, while the host and the username show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: $Mail->Config->password === $password && $Mail->Config->token === $token,
         description: 'The live password and token are untouched by the dumps'
      );

      // @ Not configured shows as it is
      $empty = $Dumper->dump(new Config());
      yield assert(
         assertion: str_contains($empty, "+password: ''") === true
            && str_contains($empty, "+token: ''") === true
            && str_contains($empty, Secret::MASK) === false,
         description: 'Empty credentials show as empty — a dump still tells "not configured"'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Own = new ReflectionMethod($Mail->Config, '__debugInfo');
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $marked = [];
      $misplaced = [];
      foreach ((new ReflectionClass(Config::class))->getProperties() as $Property) {
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
            && $marked === ['password', 'token']
            && $misplaced === [],
         description: 'Mail\Config dumps through the Redactable trait, #[Secret] on the stored password and token only'
      );
   }
);

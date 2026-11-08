<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Tests\SecureFlags;


use function assert;
use function var_export;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;


return new Test(
   description: 'Database: TLS `verify`/`name` flags read the strict boolean caster — words, 0/1 and bools only',
   test: function () {
      // ! One `verify` flag → its resolved value, or the refusal message.
      $read = static function (mixed $flag, string $host = ''): mixed {
         try {
            if ($host !== '') {
               $Config = new Config(['replicas' => [['host' => $host, 'secure' => ['verify' => $flag]]]]);

               return $Config->replicas[0]['secure']['verify'];
            }

            $Config = new Config(['secure' => ['mode' => Config::SECURE_REQUIRE, 'verify' => $flag]]);

            return $Config->secure['verify'];
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }
      };

      // # Accepted: [input, expected]
      $accepted = [
         [true, true],
         [false, false],
         [1, true],
         [0, false],
         ['1', true],
         ['0', false],
         ['true', true],
         ['FALSE', false],
         ['yes', true],
         ['no', false],
         ['on', true],
         [' Off ', false],
         ["1\n", true],
         [null, false],
      ];
      foreach ($accepted as [$input, $expected]) {
         $shown = var_export($input, true);

         yield assert(
            assertion: $read($input) === $expected,
            description: "TLS `verify` {$shown} resolves to " . var_export($expected, true)
         );
      }

      // # Refused: a float flag (1.2.0: no longer read through filter_var), any other int, blank or unknown words
      foreach ([1.0, 0.0, 2, -1, '', ' ', 'y', 'maybe', '2'] as $input) {
         $shown = var_export($input, true);

         yield assert(
            assertion: $read($input) === 'Database TLS `verify` must be a boolean.',
            description: "TLS `verify` {$shown} is refused at config time"
         );
      }

      yield assert(
         assertion: $read(1.0, 'r2') === 'Database TLS `verify` must be a boolean (replica r2).',
         description: 'A replica float flag is refused naming the replica'
      );
   }
);

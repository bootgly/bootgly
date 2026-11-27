<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Tests;


use function assert;
use function chr;
use function count;
use function file_get_contents;
use function glob;
use function hex2bin;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::decode()` — a sweep over every captured answer: each prefix, and each
 * byte replaced by 0x00, 0xFF, 0xC0 (a pointer) and 0x3F (a long label). Every
 * result is a `Message` or a `Faults` case: no warning, no notice, no
 * exception — a forgotten bound check shows up here as an error.
 */

return new Test(
   description: 'DNS answers every prefix and byte variant of real answers with a Message or a fault',
   test: function () {
      $errors = [];
      set_error_handler(function (int $level, string $message) use (&$errors): bool {
         $errors[] = $message;

         return true;
      });

      $decoded = 0;
      $wrong = [];
      try {
         foreach (glob(__DIR__ . '/fixtures/*.txt') ?: [] as $file) {
            $bytes = (string) hex2bin(str_replace("\n", '', (string) file_get_contents($file)));
            $length = strlen($bytes);

            // @@ Every prefix, then every single-byte variant
            $variants = [];
            for ($size = 0; $size < $length; $size++) {
               $variants[] = substr($bytes, 0, $size);
            }
            for ($at = 0; $at < $length; $at++) {
               foreach ([0x00, 0xFF, 0xC0, 0x3F] as $byte) {
                  $variants[] = substr($bytes, 0, $at) . chr($byte) . substr($bytes, $at + 1);
               }
            }

            foreach ($variants as $variant) {
               try {
                  $Result = DNS::decode($variant);
               }
               catch (Throwable $Throwable) {
                  $wrong[] = $Throwable->getMessage();
                  continue;
               }
               if (($Result instanceof Message || $Result instanceof Faults) === false) {
                  $wrong[] = 'not a Message nor a fault';
               }
               $decoded++;
            }
         }
      }
      finally {
         restore_error_handler();
      }

      yield assert(
         assertion: $decoded > 10000 && $errors === [] && $wrong === [],
         description: "{$decoded} variants decoded: " . count($errors) . ' PHP errors, ' . count($wrong) . ' throws'
            . ($errors === [] ? '' : " (first: {$errors[0]})")
            . ($wrong === [] ? '' : " (first: {$wrong[0]})")
      );
   }
);

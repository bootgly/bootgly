<?php

use Bootgly\ACI\Tests\Suite\Test;

return new Test(
   description: 'WPI::autoboot() refuses every non-CLI SAPI',
   test: function () {
      // ! A child process boots the framework under a foreign SAPI name
      $root = dirname(__DIR__, 3);
      $binary = escapeshellarg(PHP_BINARY);
      $script = escapeshellarg("{$root}/autoboot.php");
      $lane = '-r ' . escapeshellarg('require $_SERVER["argv"][1];');

      $refused = (string) shell_exec(
         "BOOTGLY_SAPI=fpm-fcgi {$binary} {$lane} {$script} 2>&1"
      );
      yield assert(
         assertion: str_contains($refused, 'web SAPIs'),
         description: 'a web SAPI boot dies with the explicit refusal'
      );

      // ! Control — the CLI SAPI boots without the refusal
      $clean = (string) shell_exec(
         "BOOTGLY_SAPI=cli {$binary} {$lane} {$script} 2>&1"
      );
      yield assert(
         assertion: str_contains($clean, 'web SAPIs') === false,
         description: 'the CLI SAPI boots without the refusal'
      );
   }
);

<?php

use Bootgly\ACI\Mail;
use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Mail\Config: defaults, coercion and validation',
   test: function () {
      // @ Defaults
      $Config = new Config();

      yield assert(
         assertion: $Config->host === '127.0.0.1',
         description: 'host defaults to 127.0.0.1'
      );
      yield assert(
         assertion: $Config->port === 587,
         description: 'port defaults to 587'
      );
      yield assert(
         assertion: $Config->secure === Config::SECURE_STARTTLS,
         description: 'secure defaults to starttls'
      );
      yield assert(
         assertion: $Config->verify === true,
         description: 'certificate verification defaults to true (fail-closed)'
      );
      yield assert(
         assertion: $Config->cafile === '' && $Config->peer === '',
         description: 'cafile and peer default to empty (system CA / host SNI)'
      );
      yield assert(
         assertion: $Config->username === '' && $Config->password === '' && $Config->token === '',
         description: 'credentials default to empty (AUTH disabled)'
      );
      yield assert(
         assertion: $Config->domain !== '',
         description: 'domain falls back to a non-empty EHLO client name'
      );
      yield assert(
         assertion: $Config->timeout === 10.0 && $Config->wait === 30.0 && $Config->drain === 120.0,
         description: 'timeouts default to 10s connect / 30s reply / 120s drain'
      );
      yield assert(
         assertion: $Config->insecure === false,
         description: 'insecure (plaintext AUTH) defaults to false'
      );
      yield assert(
         assertion: $Config->trace === null,
         description: 'trace hook defaults to null'
      );

      // @ Coercion
      $Config = new Config([
         'host' => 'smtp.example.com',
         'port' => '2525',
         'secure' => Config::SECURE_TLS,
         'verify' => 'yes',
         'timeout' => 5,
         'domain' => 'client.example.com',
         'trace' => 'not-a-closure'
      ]);

      yield assert(
         assertion: $Config->host === 'smtp.example.com',
         description: 'host is taken from the config array'
      );
      yield assert(
         assertion: $Config->port === 2525,
         description: 'numeric string port is coerced to int'
      );
      yield assert(
         assertion: $Config->secure === Config::SECURE_TLS,
         description: 'secure accepts the tls (implicit) mode'
      );
      yield assert(
         assertion: $Config->verify === true,
         description: 'non-bool verify falls back to the default (true)'
      );
      yield assert(
         assertion: $Config->timeout === 5.0,
         description: 'int timeout is coerced to float'
      );
      yield assert(
         assertion: $Config->domain === 'client.example.com',
         description: 'explicit domain is kept as the EHLO client name'
      );
      yield assert(
         assertion: $Config->trace === null,
         description: 'non-Closure trace falls back to null'
      );

      $Config = new Config([
         'secure' => Config::SECURE_NONE,
         'trace' => function (string $direction, string $line): void {}
      ]);
      yield assert(
         assertion: $Config->secure === Config::SECURE_NONE,
         description: 'secure accepts the none mode'
      );
      yield assert(
         assertion: $Config->trace instanceof Closure,
         description: 'Closure trace hook is kept'
      );

      // @ Validation
      $caught = false;
      try {
         new Config(['secure' => 'ssl']);
      }
      catch (InvalidArgumentException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'unknown secure mode throws InvalidArgumentException (never guessed)'
      );

      $invalids = [
         'empty host' => ['host' => ''],
         'port 0' => ['port' => 0],
         'port out of range' => ['port' => 65536],
         'non-numeric port string' => ['port' => 'smtp'],
         'zero timeout' => ['timeout' => 0],
         'negative wait' => ['wait' => -1],
         'zero drain' => ['drain' => 0.0],
         'domain with a space' => ['domain' => 'bad domain'],
         'domain with brackets' => ['domain' => 'bad<domain>']
      ];
      foreach ($invalids as $description => $config) {
         $caught = false;
         try {
            new Config($config);
         }
         catch (InvalidArgumentException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: "invalid operational value throws at construction ({$description})"
         );
      }

      $Config = new Config(['domain' => '[127.0.0.1]']);
      yield assert(
         assertion: $Config->domain === '[127.0.0.1]',
         description: 'a bracketed address literal is a valid EHLO client name'
      );

      // @ Mail facade construction
      $Mail = new Mail(['host' => 'smtp.example.net']);
      yield assert(
         assertion: $Mail->Config->host === 'smtp.example.net',
         description: 'Mail builds its Config from a plain array'
      );

      $Config = new Config(['host' => 'smtp.example.org']);
      $Mail = new Mail($Config);
      yield assert(
         assertion: $Mail->Config === $Config,
         description: 'Mail reuses a prepared Config instance'
      );
   }
);

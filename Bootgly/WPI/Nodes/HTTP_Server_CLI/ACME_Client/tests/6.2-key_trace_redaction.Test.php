<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Tests;


use function array_slice;
use function assert;
use function bin2hex;
use function count;
use function file_put_contents;
use function implode;
use function ini_get;
use function ini_set;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function print_r;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function str_contains;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use Closure;
use ErrorException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Certificates;


/**
 * Private key PEMs pass through the certificate store and the account: the
 * issued key into `install()`, the bootstrap PEM (certificate and key in one
 * file) through `verify()` and `split()`, every key file through `write()`,
 * and the account key through `Account::persist()`. With
 * `zend.exception_ignore_args=0` (the development and CI setting) a trace
 * holds every argument of every frame, so no such frame may show the key.
 *
 * Real throws: an empty chain refused by `install()`, and a bootstrap whose
 * certificate block does not parse — the OpenSSL warning escalated, as the
 * Bootgly error handler does, under `snapshot()` → `verify()`. `write()` and
 * `Account::persist()` are private and refuse a path outside the store or
 * through a symlink: the spec calls each directly with such a path, bound to
 * its object. `split()` returns from under `verify()` and `install()` before
 * any throw: it is pinned by reflection.
 */
return new Test(
   description: 'ACME store and account: private key PEMs stay out of every stack trace',
   test: function () {
      // ! SYNTH key material — never a real key (the bootstrap one uses the base64 alphabet its grammar requires)
      $installed = 'SYNTH-ACME-INSTALL-KEY-6.2';
      $bootstrap = 'SYNTHxACMExBOOTSTRAPxKEYx62';
      $written = 'SYNTH-ACME-WRITTEN-KEY-6.2';
      $account = 'SYNTH-ACME-ACCOUNT-KEY-6.2';
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! The trace's own frames: every frame up to the last call made from this file — the
      //   runner's frames below it hold the suite state, which a full run makes unbounded
      $Frames = static function (Throwable $Throwable): array {
         $Trace = $Throwable->getTrace();
         $last = 0;
         foreach ($Trace as $index => $Frame) {
            if (($Frame['file'] ?? '') === __FILE__) {
               $last = $index;
            }
         }

         return array_slice($Trace, 0, $last + 1);
      };
      // ! Each channel a trace reaches
      $Channels = [
         'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
         'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
         'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
      ];
      /**
       * ! What a chain got wrong: an unmarked frame argument, a key a channel shows
       *
       * @param array<int,array{0:class-string,1:string,2:int}> $Marked
       * @param array<string,string> $secrets
       *
       * @return array<int,string>
       */
      $Check = static function (null|Throwable $Thrown, array $Marked, array $secrets) use ($Channels): array {
         // ?
         if ($Thrown === null) {
            return ['nothing was thrown'];
         }

         $wrong = [];
         // @@ Each frame the key passes through
         foreach ($Marked as [$class, $function, $position]) {
            $redacted = false;
            foreach ($Thrown->getTrace() as $Frame) {
               if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
                  $redacted = ($Frame['args'][$position] ?? null) instanceof SensitiveParameterValue;
                  break;
               }
            }
            if ($redacted === false) {
               $wrong[] = "{$class}::{$function}() argument #{$position} is not a SensitiveParameterValue";
            }
         }
         // @@ Each channel
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Thrown);

            foreach ($secrets as $name => $secret) {
               if (str_contains($output, $secret) === true) {
                  $wrong[] = "the {$name} via {$channel}";
               }
            }
         }

         return $wrong;
      };

      // @ Every frame a key PEM passes through marks it (attributes are not inherited — each frame is pinned)
      $Parameters = [
         [Certificates::class, 'install', 'key'],
         [Certificates::class, 'verify', 'certificatePEM'],
         [Certificates::class, 'verify', 'keyPEM'],
         [Certificates::class, 'split', 'bundle'],
         [Certificates::class, 'write', 'contents'],
         [Account::class, 'persist', 'contents'],
      ];
      $unmarked = [];
      foreach ($Parameters as [$class, $method, $parameter]) {
         $Parameter = new ReflectionParameter([$class, $method], $parameter);
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}() \${$parameter}";
         }
      }
      $listed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Certificates and Account mark every key PEM parameter #[SensitiveParameter] (unmarked: [{$listed}])"
      );

      // ! A store with a broken bootstrap, a symlinked directory, an account
      $temporary = sys_get_temp_dir();
      $suffix = bin2hex(random_bytes(8));
      $root = "{$temporary}/bootgly-acme-traces-{$suffix}";
      $Cleanup = null;
      $Cleanup = static function (string $path) use (&$Cleanup): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Cleanup("{$path}/{$entry}");
         }
         @rmdir($path);
      };
      // ! Traces with arguments, at full length — the development and CI setting
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignoring = ini_set('zend.exception_ignore_args', '0');
      $lengthening = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $ignoring !== false && $lengthening !== false,
            description: 'The run takes traces with arguments at full length'
         );

         mkdir("{$root}/store", 0700, true);
         mkdir("{$root}/real", 0700);
         symlink("{$root}/real", "{$root}/link");
         // ! The PEM label is built so the tree's key guard (API Environment
         //   `1.5-tracked-certificates`) never meets a key marker in this file
         $label = 'PRIVATE KEY';
         file_put_contents("{$root}/store/bootstrap.pem", implode("\n", [
            '-----BEGIN CERTIFICATE-----',
            'AAAA',
            '-----END CERTIFICATE-----',
            "-----BEGIN {$label}-----",
            $bootstrap,
            "-----END {$label}-----",
            ''
         ]));
         $Certificates = new Certificates("{$root}/store");
         $Account = new Account("{$root}/account");

         // @ Positive control: an unmarked frame shows every key in every channel
         $secrets = ['installed key' => $installed, 'bootstrap key' => $bootstrap, 'written key' => $written, 'account key' => $account];
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked($secrets, $installed, $bootstrap, $written, $account);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Check($Control, [], $secrets);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($secrets) * count($Channels),
            description: "An unmarked frame shows each key in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ install(): an empty chain is refused under the frame that holds the key
         $Thrown = null;
         try {
            $Certificates->install('', $installed);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check($Thrown, [[Certificates::class, 'install', 1]], ['installed key' => $installed]);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof RuntimeException
               && $Thrown->getMessage() === 'ACME install received an empty certificate chain.'
               && $wrong === [],
            description: "An install refusal keeps the issued key out of the trace (wrong: [{$listed}])"
         );

         // @ snapshot() → verify(): the broken bootstrap certificate, its warning escalated
         $Thrown = null;
         set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
         });
         try {
            $Certificates->snapshot();
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         finally {
            restore_error_handler();
         }
         $wrong = $Check(
            $Thrown,
            [[Certificates::class, 'verify', 3], [Certificates::class, 'verify', 4]],
            ['bootstrap key' => $bootstrap]
         );
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof ErrorException && $wrong === [],
            description: "A broken bootstrap keeps its key out of the trace under snapshot() and verify() (wrong: [{$listed}])"
         );

         // @ write(): a key file outside the store is refused
         $Write = Closure::bind(
            function () use ($written): void {
               // @phpstan-ignore method.private (bound to the store: its private writer, called directly)
               $this->write('/outside/privkey.pem', $written, 0600);
            },
            $Certificates,
            Certificates::class
         );
         $Thrown = null;
         try {
            $Write();
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check($Thrown, [[Certificates::class, 'write', 1]], ['written key' => $written]);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof RuntimeException
               && $Thrown->getMessage() === 'ACME storage file `/outside/privkey.pem` is outside the safe boundary.'
               && $wrong === [],
            description: "A refused key file keeps its contents out of the trace (wrong: [{$listed}])"
         );

         // @ Account::persist(): an account file through a symlink is refused
         $Persist = Closure::bind(
            function () use ($root, $account): void {
               // @phpstan-ignore method.private (bound to the account: its private writer, called directly)
               $this->persist("{$root}/link/key.pem", $account);
            },
            $Account,
            Account::class
         );
         $Thrown = null;
         try {
            $Persist();
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check($Thrown, [[Account::class, 'persist', 1]], ['account key' => $account]);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof RuntimeException
               && $Thrown->getMessage() === "ACME account file `{$root}/link/key.pem` crosses a symbolic link."
               && $wrong === [],
            description: "A refused account file keeps the account key out of the trace (wrong: [{$listed}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
         $Cleanup($root);
      }
   }
);

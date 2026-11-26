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


use function array_map;
use function assert;
use function getmypid;
use function glob;
use function in_array;
use function rmdir;
use function serialize;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
use Closure;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ConnectionException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ProtocolException;


/**
 * The ACME client resolves every approved host through the system's
 * resolver (`Bootgly\ABI\IO\Resolver`) and vets every address it answers:
 * an answer that is not an address is a protocol failure, no answer a
 * connection failure.
 */
return new Test(
   description: 'ACME name resolution: literals, loopback names, empty and void answers',
   test: function () {
      $pid = getmypid();
      $path = sys_get_temp_dir() . "/bootgly-acme-name-resolution-{$pid}/";

      /**
       * The exception a call throws, as `class: message` (`''` when it returns).
       */
      $Catch = static function (Closure $Action): string {
         try {
            $Action();
         }
         catch (Throwable $Throwable) {
            return $Throwable::class . ": {$Throwable->getMessage()}";
         }

         return '';
      };

      try {
         $Account = new Account($path);
         $Client = new ACME_Client(
            $Account,
            'https://ca.example.test/directory',
         );
         $Resolve = new ReflectionMethod($Client, 'resolve');
         $PrivateClient = new ACME_Client(
            $Account,
            'https://127.0.0.1:9443/directory',
            allowPrivate: true,
         );
         $PrivateResolve = new ReflectionMethod($PrivateClient, 'resolve');

         // @ Literals — no lookup, canonical form
         yield assert(
            assertion: $Resolve->invoke($Client, '::ffff:93.184.216.34') === '93.184.216.34'
               && $Resolve->invoke($Client, '::FFFF:5DB8:D822') === '93.184.216.34',
            description: 'an IPv4-mapped IPv6 literal resolves to the IPv4 address it carries'
         );
         yield assert(
            assertion: $Resolve->invoke($Client, '2606:2800:0220:0001:0248:1893:25C8:1946')
               === '2606:2800:220:1:248:1893:25c8:1946',
            description: 'a non-canonical IPv6 literal resolves to its canonical form'
         );

         // @ Names — the system resolver, read through the loopback name only
         $loopback = $PrivateResolve->invoke($PrivateClient, 'localhost');
         yield assert(
            assertion: in_array($loopback, ['::1', '127.0.0.1'], true),
            description: 'localhost resolves to a loopback address in private-test mode'
         );
         yield assert(
            assertion: str_starts_with(
               $Catch(static fn () => $Resolve->invoke($Client, 'localhost')),
               ProtocolException::class . ': ACME authority `localhost` resolved to prohibited address'
            ),
            description: 'localhost is refused as a prohibited address by default'
         );
         yield assert(
            assertion: $Catch(static fn () => $Resolve->invoke($Client, ''))
               === ConnectionException::class . ': ACME authority `` could not be resolved.',
            description: 'a name with no answer is a connection failure, not a protocol one'
         );

         // @ A client of the system's resolver still serializes
         $Copy = unserialize(serialize($Client));
         yield assert(
            assertion: $Copy instanceof ACME_Client
               && (new ReflectionMethod($Copy, 'resolve'))->invoke($Copy, '93.184.216.34') === '93.184.216.34',
            description: 'a client serializes and its copy still resolves'
         );

         // @ The answers of the system resolver, through the ABI resolver's sources
         $Seam = new ReflectionProperty(ACME_Client::class, 'Resolver');
         $Explain = static fn (string $IP): array => [
            'ai_addr' => str_contains($IP, ':') ? ['sin6_addr' => $IP] : ['sin_addr' => $IP],
         ];
         $Answer = static fn (false|array $answers): Closure => static fn (string $host): false|array => $answers === false
            ? false
            : array_map($Explain, $answers);

         $Seam->setValue($Client, new Resolver($Answer(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])));
         yield assert(
            assertion: $Resolve->invoke($Client, 'ca.example.test') === '93.184.216.34',
            description: 'a name resolves to its first answer once every answer is admitted'
         );
         $Seam->setValue($Client, new Resolver($Answer(['93.184.216.34', '10.0.0.1'])));
         yield assert(
            assertion: $Catch(static fn () => $Resolve->invoke($Client, 'ca.example.test'))
               === ProtocolException::class . ': ACME authority `ca.example.test` resolved to prohibited address `10.0.0.1`.',
            description: 'a name with one prohibited answer is refused, whatever its first answer'
         );
         $Seam->setValue($Client, new Resolver($Answer(['93.184.216.34', 'not-an-address'])));
         yield assert(
            assertion: $Catch(static fn () => $Resolve->invoke($Client, 'ca.example.test'))
               === ProtocolException::class . ': ACME authority `ca.example.test` resolved to an invalid address.',
            description: 'an answer that is not an address is a protocol failure (void), not an empty answer'
         );
         $Seam->setValue($Client, new Resolver($Answer(false)));
         yield assert(
            assertion: $Catch(static fn () => $Resolve->invoke($Client, 'ca.example.test'))
               === ConnectionException::class . ': ACME authority `ca.example.test` could not be resolved.',
            description: 'a failed lookup is a connection failure (empty), not a protocol one'
         );

         // @ Without ext-sockets: the IPv4 answers, then the absolute name's AAAA records — every one vetted
         $queried = [];
         $Query = static function (false|array $records) use (&$queried): Closure {
            return static function (string $name) use ($records, &$queried): false|array {
               $queried[] = $name;

               return $records;
            };
         };
         $Seam->setValue($Client, new Resolver(
            false,
            static fn (string $host): array => ['93.184.216.34'],
            $Query([['ipv6' => '2606:2800:220:1:248:1893:25c8:1946']]),
         ));
         $socketless = $Resolve->invoke($Client, 'CA.Example.Test');
         $Seam->setValue($Client, new Resolver(
            false,
            static fn (string $host): array => ['93.184.216.34'],
            $Query([['ipv6' => '::1']]),
         ));
         $vetted = $Catch(static fn () => $Resolve->invoke($Client, 'ca.example.test.'));
         yield assert(
            assertion: $socketless === '93.184.216.34'
               && $queried === ['ca.example.test.', 'ca.example.test.']
               && $vetted === ProtocolException::class . ': ACME authority `ca.example.test.` resolved to prohibited address `::1`.',
            description: 'without ext-sockets, the AAAA records of the absolute name are asked and vetted too'
         );
         $queried = [];
         $Seam->setValue($PrivateClient, new Resolver(
            false,
            static fn (string $host): array => ['127.0.0.1'],
            $Query([['ipv6' => '2001:db8::1']]),
         ));
         yield assert(
            assertion: $PrivateResolve->invoke($PrivateClient, 'localhost') === '127.0.0.1' && $queried === [],
            description: 'without ext-sockets, localhost never reaches DNS'
         );
      }
      finally {
         foreach (glob("{$path}*") ?: [] as $file) {
            @unlink($file);
         }
         @rmdir($path);
      }
   },
);

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Seams;


use function class_exists;
use function count;
use function file_put_contents;
use function is_array;
use function is_int;
use function json_encode;
use function range;
use function rmdir;
use function serialize;
use function unlink;
use Closure;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Seams;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


// ! A base whose static factory reads the class it is called on (late static binding)
if (class_exists(Zone::class, false) === false) {
   abstract class Zone
   {
      public const null|string SOURCE = null;

      public static function probe (): Closure
      {
         return static fn (int $family): null|string => static::SOURCE;
      }

      public static function resolver (): Resolver
      {
         return new Resolver(
            Lookup: false,
            List: static fn (string $host): array => [static::SOURCE],
            Query: static fn (string $host): false => false,
            nsswitch: null
         );
      }
   }
}

return new Test(
   description: 'DNS_Client_CLI shares a state between Configs whose seams run the same code with the same captures',
   test: function () {
      // ! Seam factories: one closure site each, any capture
      $probe = static function (int $last): Closure {
         return static fn (int $family): null|string => $family === 4 ? "192.0.2.{$last}" : null;
      };
      $track = static function (int &$last): Closure {
         return static function (int $family) use (&$last): null|string {
            return $family === 4 ? "192.0.2.{$last}" : null;
         };
      };
      $upstream = static function (string $address, null|string $nsswitch = null): Resolver {
         return new Resolver(
            Lookup: false,
            List: static fn (string $host): array => [$address],
            Query: static fn (string $host): false => false,
            nsswitch: $nsswitch
         );
      };
      $ladder = static function (): Resolver {
         return new Resolver(
            Lookup: static fn (string $host): false => false,
            List: static fn (string $host): false => false,
            Query: static fn (string $host): false => false,
            nsswitch: null
         );
      };

      $Harness = Harness::start('dns-seams');
      $NS = $Harness->Nameserver;
      $NS->script('seams.test', DNS::TYPE_A, ['answers' => [['seams.test', DNS::TYPE_A, 300, '192.0.2.77']]]);
      $values = ['hostname' => 'seams.example'];
      // ! The seams stay alive for the whole case, as a state keeps its Configs alive
      $Alive = [];
      $ask = static function (array $seams) use ($Harness, $values, &$Alive): void {
         $Configs = $Harness->configure($seams + $values);
         $Alive[] = $Configs;
         new DNS_Client_CLI()->configure($Configs)->query('seams.test', DNS::TYPE_A);
      };

      try {
         // @ A Configs built per lookup, its probe from one static closure site: one state, one query
         foreach ([1, 2, 3] as $lookup) {
            $ask([]);
         }
         $queries = count($NS->log());

         yield new Assertion(
            description: 'a Configs rebuilt per lookup with the same static probe shares the state: one query',
            fallback: "The nameserver saw {$queries} queries for 3 lookups (1 expected)."
         )
            ->expect($queries)
            ->to->be(1)
            ->assert();

         // @ The same site with other captured values behaves differently: its own state
         $NS->reset();
         $ask(['Probe' => $probe(51)]);
         $ask(['Probe' => $probe(52)]);
         $ask(['Probe' => $probe(51)]);
         $queries = count($NS->log());

         yield new Assertion(
            description: 'probes from one site with different captures get separate states; equal captures share',
            fallback: "The nameserver saw {$queries} queries (2 expected: one per captured value)."
         )
            ->expect($queries)
            ->to->be(2)
            ->assert();

         // @ A by-reference capture keeps the object identity: never shared
         $NS->reset();
         $last = 53;
         $ask(['Probe' => $track($last)]);
         $ask(['Probe' => $track($last)]);
         $queries = count($NS->log());

         yield new Assertion(
            description: 'a probe capturing by reference is keyed by identity: one state per object',
            fallback: "The nameserver saw {$queries} queries (2 expected)."
         )
            ->expect([$queries, is_int(Seams::identify($track($last)))])
            ->to->be([2, true])
            ->assert();

         // @ A Resolver rebuilt per lookup from the same sources shares the state
         $NS->reset();
         $ask(['Resolver' => $ladder(), 'Probe' => $probe(54)]);
         $ask(['Resolver' => $ladder(), 'Probe' => $probe(54)]);
         $queries = count($NS->log());

         yield new Assertion(
            description: 'a Resolver rebuilt per lookup with the same sources shares the state',
            fallback: "The nameserver saw {$queries} queries (1 expected)."
         )
            ->expect($queries)
            ->to->be(1)
            ->assert();

         // @ Resolvers whose sources differ — a captured upstream, the called class — never share
         $East = new class extends Zone {
            public const null|string SOURCE = '192.0.2.61';
         };
         $West = new class extends Zone {
            public const null|string SOURCE = '198.51.100.61';
         };
         $NS->reset();
         $ask(['Resolver' => $upstream('192.0.2.71'), 'Probe' => $probe(56)]);
         $ask(['Resolver' => $upstream('192.0.2.72'), 'Probe' => $probe(56)]);
         $ask(['Resolver' => $East::resolver(), 'Probe' => $probe(56)]);
         $ask(['Resolver' => $West::resolver(), 'Probe' => $probe(56)]);
         $queries = count($NS->log());

         yield new Assertion(
            description: 'Resolvers with other sources get separate states, even from one static factory',
            fallback: "The nameserver saw {$queries} queries (4 expected: one per Resolver)."
         )
            ->expect($queries)
            ->to->be(4)
            ->assert();

         // @ What can never be told apart by code keeps the identity
         $Pair = [static fn (int $family): null => null, static fn (int $family): string => '192.0.2.9'];
         $Evaluated = eval('return static fn (int $family): null => null;');
         $Counter = static function (int $family): null|string {
            static $calls = 0;
            $calls++;

            return null;
         };
         $Owner = new class (4) {
            public int $family;
            public function __construct (int $family)
            {
               $this->family = $family;
            }
            public function probe (): Closure
            {
               return fn (int $family): null|string => $family === $this->family ? '192.0.2.10' : null;
            }
         };
         $Other = new $Owner(6);
         // ! A seam from a file written after this process started: the code that runs may not be the file's
         $directory = Temporaries::reserve('dns-seams');
         file_put_contents("{$directory}/late.php", "<?php\nreturn static fn (int \$family): null => null;\n");
         $Late = require "{$directory}/late.php";
         // ! A capture wider than a key should carry
         $Wide = range(1, Seams::WIDTH + 1);
         $Broad = static fn (int $family): null|string => $Wide[0] === 0 ? '192.0.2.62' : null;
         $Narrow = range(1, Seams::WIDTH);
         $Fitting = static fn (int $family): null|string => $Narrow[0] === 0 ? '192.0.2.63' : null;
         // ! Pairs held alive together: a freed seam's object id is reused, and identity keys would match
         $Owned = [$Owner->probe(), $Owner->probe()];
         $Foreign = [$Owner->probe(), $Other->probe()];
         $Equal = [$probe(1), $probe(1)];
         $Called = [$East::probe(), $East::probe()];
         $Classes = [$East::probe(), $West::probe()];
         $Upstreams = [$upstream('192.0.2.71'), $upstream('192.0.2.72')];
         $Switches = [$upstream('192.0.2.71'), $upstream('192.0.2.71', '/etc/nsswitch.conf')];
         $Factories = [$East::resolver(), $West::resolver()];
         // ! Both keyed by code, and equal / apart
         $same = static function (array $Seams): bool {
            $keys = [Seams::identify($Seams[0]), Seams::identify($Seams[1])];

            return is_array($keys[0]) && is_array($keys[1]) && serialize($keys[0]) === serialize($keys[1]);
         };
         $apart = static function (array $Seams): bool {
            $keys = [Seams::identify($Seams[0]), Seams::identify($Seams[1])];

            return is_array($keys[0]) && is_array($keys[1]) && serialize($keys[0]) !== serialize($keys[1]);
         };
         $keys = [
            'two on one line' => is_int(Seams::identify($Pair[0])),
            'eval' => is_int(Seams::identify($Evaluated)),
            'static variable' => is_int(Seams::identify($Counter)),
            'same code' => is_array(Seams::identify($probe(55))),
            'other $this' => $apart($Foreign),
            'same $this' => $same($Owned),
            'equal capture' => $same($Equal),
            'other called class' => $apart($Classes),
            'same called class' => $same($Called),
            'other Resolver sources' => $apart($Upstreams),
            'other nsswitch' => $apart($Switches),
            'Resolvers from other classes' => $apart($Factories),
            'capture at the width' => is_array(Seams::identify($Fitting)),
            'file changed since start' => is_int(Seams::identify($Late)),
            'wide capture' => is_int(Seams::identify($Broad)),
            'no seam' => Seams::identify(null) === null,
         ];
         // ! The file stays until it is keyed: a missing file would key by identity for another reason
         unlink("{$directory}/late.php");
         rmdir($directory);

         yield new Assertion(
            description: 'identify(): identity for what code cannot key, code for the rest',
            fallback: 'Seams::identify() disagreed: ' . json_encode($keys)
         )
            ->expect($keys)
            ->to->be([
               'two on one line' => true,
               'eval' => true,
               'static variable' => true,
               'same code' => true,
               'other $this' => true,
               'same $this' => true,
               'equal capture' => true,
               'other called class' => true,
               'same called class' => true,
               'other Resolver sources' => true,
               'other nsswitch' => true,
               'Resolvers from other classes' => true,
               'capture at the width' => true,
               'file changed since start' => true,
               'wide capture' => true,
               'no seam' => true,
            ])
            ->assert();
      }
      finally {
         $Harness->stop();
      }
   }
);

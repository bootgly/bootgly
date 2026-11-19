<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function str_replace;
use function var_dump;
use Closure;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;


class RedactedBase
{
   use Redactable;


   // * Data
   #[Secret]
   private string $secret = 'SYNTH-BASE-PRIVATE';   // @phpstan-ignore property.onlyWritten
   #[Secret]
   protected string $token = 'SYNTH-PROTECTED';
   public string $name = 'visible-name';
}

class RedactedChild extends RedactedBase
{
   // * Data
   public static int $hits = 0;
   #[Secret]
   private string $secret = 'SYNTH-CHILD-PRIVATE';   // @phpstan-ignore property.onlyWritten
   /** @var array<string,mixed> */
   #[Secret(['password', 'Authorization'])]
   public array $options = [
      'host' => 'db.invalid',
      'PASSWORD' => 'SYNTH-KEYED',
      'nested' => ['authorization' => 'SYNTH-NESTED'],
      'empty' => ''
   ];
   #[Secret(URI: true)]
   public string $endpoint = 'https://SYNTH-USERINFO@host.invalid/x';
   #[Secret]
   public null|string $unset = null;
   #[Secret]
   public string $blank = '';
   #[Secret]
   public string $reference = 'SYNTH-REF';
   public int $count;
   public string $label {
      get => 'computed';
   }
   public string $hooked = 'raw' {
      get {
         self::$hits++;

         return $this->hooked;
      }
   }
}

// ! The same shape without the trait — what PHP shows unredacted
class RedactedTwinBase
{
   // * Data
   private string $secret = 'SYNTH-BASE-PRIVATE';   // @phpstan-ignore property.onlyWritten
   protected string $token = 'SYNTH-PROTECTED';
   public string $name = 'visible-name';
}

class RedactedTwinChild extends RedactedTwinBase
{
   // * Data
   private string $secret = 'SYNTH-CHILD-PRIVATE';   // @phpstan-ignore property.onlyWritten
   /** @var array<string,mixed> */
   public array $options = [
      'host' => 'db.invalid',
      'PASSWORD' => 'SYNTH-KEYED',
      'nested' => ['authorization' => 'SYNTH-NESTED'],
      'empty' => ''
   ];
   public string $endpoint = 'https://SYNTH-USERINFO@host.invalid/x';
   public null|string $unset = null;
   public string $blank = '';
   public string $reference = 'SYNTH-REF';
   public int $count;
   public string $label {
      get => 'computed';
   }
   public string $hooked = 'raw';
}

// ! A subclass keeps its own `__debugInfo()` — untyped or `?array` — because the trait's is untyped
class RedactedOverride extends RedactedBase
{
   /** @return array<string,bool> */
   public function __debugInfo ()   // @phpstan-ignore missingType.return
   {
      return ['own' => true];
   }
}

class RedactedNullable extends RedactedBase
{
   /** @return null|array<string,mixed> */
   public function __debugInfo (): null|array
   {
      return null;
   }
}

return new Test(
   description: 'Redactable masks #[Secret] properties in every debug dump and changes nothing else',
   test: function () {
      $Dumper = new Dumper('plain');
      $secrets = [
         'SYNTH-BASE-PRIVATE', 'SYNTH-PROTECTED', 'SYNTH-CHILD-PRIVATE', 'SYNTH-KEYED', 'SYNTH-NESTED',
         'SYNTH-USERINFO', 'SYNTH-REF'
      ];
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

      $Object = new RedactedChild;
      // ! Live references — one on a whole secret, one inside a keyed array
      $live = 'SYNTH-REF';
      $Object->reference = &$live;
      $password = 'SYNTH-KEYED';
      $Object->options['PASSWORD'] = &$password;

      // @ Every channel
      $outputs = [];
      $leaks = [];
      $missing = [];
      foreach ($Channels as $channel => $Channel) {
         $outputs[$channel] = $output = $Channel($Object);
         foreach ($secrets as $secret) {
            if (str_contains($output, $secret) === true) {
               $leaks[] = "{$channel}: {$secret}";
            }
         }
         foreach (['visible-name', 'db.invalid', 'https://host.invalid/x', Secret::MASK] as $shown) {
            if (str_contains($output, $shown) === false) {
               $missing[] = "{$channel}: {$shown}";
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No secret reaches var_dump, print_r, debug_zval_dump or Dumper — private, protected, ancestor, "
            . "same-named, keyed at any depth, URL userinfo — while plain values show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ The live values are untouched — references included
      $Read = Closure::bind(
         static fn (RedactedChild $Object): array => [$Object->secret, $Object->token],
         null,
         RedactedChild::class
      );
      $ReadBase = Closure::bind(
         static fn (RedactedBase $Object): string => $Object->secret,
         null,
         RedactedBase::class
      );
      yield assert(
         assertion: $live === 'SYNTH-REF'
            && $Object->reference === 'SYNTH-REF'
            && $password === 'SYNTH-KEYED'
            && $Object->options['PASSWORD'] === 'SYNTH-KEYED'
            && $Object->options['nested'] === ['authorization' => 'SYNTH-NESTED']
            && $Object->endpoint === 'https://SYNTH-USERINFO@host.invalid/x'
            && $Read($Object) === ['SYNTH-CHILD-PRIVATE', 'SYNTH-PROTECTED']
            && $ReadBase($Object) === 'SYNTH-BASE-PRIVATE',
         description: 'Dumping never writes: the live values — and the references held on them — keep the secrets'
      );

      // @ No get hook runs
      yield assert(
         assertion: RedactedChild::$hits === 0,
         description: 'No channel runs a get hook'
      );

      // @ print_r: what PHP prints without the trait, the masked values excepted
      $twin = print_r(new RedactedTwinChild, true);
      $expected = str_replace(
         ['RedactedTwin', 'SYNTH-BASE-PRIVATE', 'SYNTH-PROTECTED', 'SYNTH-CHILD-PRIVATE', 'SYNTH-KEYED',
            'SYNTH-NESTED', 'https://SYNTH-USERINFO@', 'SYNTH-REF'],
         ['Redacted', Secret::MASK, Secret::MASK, Secret::MASK, Secret::MASK,
            Secret::MASK, 'https://', Secret::MASK],
         $twin
      );
      yield assert(
         assertion: $outputs['print_r'] === $expected,
         description: 'print_r shows the same properties, names, visibilities and order as without the trait'
      );

      // @ var_dump: the same visibility annotations, `null` and `''` as they are
      yield assert(
         assertion: str_contains($outputs['var_dump'], '["secret":"Bootgly\ABI\Debugging\Data\Vars\RedactedBase":private]=>') === true
            && str_contains($outputs['var_dump'], '["secret":"Bootgly\ABI\Debugging\Data\Vars\RedactedChild":private]=>') === true
            && str_contains($outputs['var_dump'], "[\"token\":protected]=>\n  string(10) \"[redacted]\"") === true
            && str_contains($outputs['var_dump'], "[\"unset\"]=>\n  NULL") === true
            && str_contains($outputs['var_dump'], "[\"blank\"]=>\n  string(0) \"\"") === true,
         description: 'var_dump keeps the private and protected annotations, and shows null and empty secrets as they are'
      );

      // @ Dumper: the walk — sigils, origins, the masked values, and the value-less rows
      $expected = <<<'DUMP'
      Bootgly\ABI\Debugging\Data\Vars\RedactedChild {
         -secret: '[redacted]'
         +options: array:4 [
            'host' => 'db.invalid'
            'PASSWORD' => '[redacted]'
            'nested' => array:1 [
               'authorization' => '[redacted]'
            ]
            'empty' => ''
         ]
         +endpoint: 'https://host.invalid/x'
         +unset: null
         +blank: ''
         +reference: '[redacted]'
         +count: uninitialized
         +label: virtual
         +hooked: 'raw'
         #token: '[redacted]'
         +name: 'visible-name'
         -secret: '[redacted]' (RedactedBase)
      }
      DUMP;
      yield assert(
         assertion: $outputs['Dumper'] === $expected,
         description: 'Dumper renders the walk with the masked values and keeps the uninitialized and virtual rows'
      );

      // @ A subclass's own __debugInfo — untyped or `?array` — compiles and wins
      yield assert(
         assertion: $Dumper->dump(new RedactedOverride) === "Bootgly\\ABI\\Debugging\\Data\\Vars\\RedactedOverride {\n   'own' => true\n}"
            && $Dumper->dump(new RedactedNullable) === 'Bootgly\ABI\Debugging\Data\Vars\RedactedNullable {}',
         description: 'A subclass may still declare its own __debugInfo, untyped or ?array'
      );

      // @ The holder check every suite runs — `__debugInfo` declared by the trait — tells an override apart
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $Check = static function (object $Holder) use ($Trait): bool {
         $Own = new ReflectionMethod($Holder, '__debugInfo');

         return $Own->getFileName() === $Trait->getFileName() && $Own->getStartLine() === $Trait->getStartLine();
      };
      yield assert(
         assertion: $Check(new RedactedChild) === true
            && $Check(new RedactedOverride) === false
            && $Check(new RedactedNullable) === false,
         description: 'The trait-origin check accepts a holder and rejects a class that overrides __debugInfo'
      );
   }
);

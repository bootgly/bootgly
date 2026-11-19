<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use const BOOTGLY_STORAGE_DIR;
use const E_USER_DEPRECATED;
use function assert;
use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function get_debug_type;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function mkdir;
use function rmdir;
use function serialize;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_starts_with;
use function strlen;
use function substr;
use function symlink;
use function trigger_error;
use function unlink;
use function unserialize;
use AssertionError;
use DateTime;
use DateTimeImmutable;
use stdClass;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Snapshots\FileStorageSnapshot;
use Bootgly\ACI\Tests\Suite\Test;


// ! A class whose magic methods would run if a snapshot could instantiate it
final class Snapshot_File_Gadget
{
   public static bool $woken = false;
   public static bool $destroyed = false;
   public string $marker = 'gadget';


   public function __wakeup (): void
   {
      self::$woken = true;
   }

   public function __destruct ()
   {
      self::$destroyed = true;
   }
}

final class Snapshot_File_Value
{
   public int $n = 2;
}

// ! A value object holding another by a typed property — listing only the outer one is not enough
final class Snapshot_File_Currency
{
   public string $code = 'USD';
}

final class Snapshot_File_Money
{
   public int $cents;
   public Snapshot_File_Currency $Currency;


   public function __construct (int $cents)
   {
      $this->cents = $cents;
      $this->Currency = new Snapshot_File_Currency;
   }
}

// ! A value object whose wakeup raises a deprecation — after unserialize() has reported its own warnings
final class Snapshot_File_Wakeful
{
   public int $cents = 1999;


   public function __wakeup (): void
   {
      trigger_error('A wakeful value deprecates something as it wakes.', E_USER_DEPRECATED);
   }
}

return new Test(
   description: 'FileStorageSnapshot restores only data and its allowed classes, and every capture persists',
   test: function () {
      // ! Scratch snapshots — named, under the storage directory, removed at the end
      $directory = BOOTGLY_STORAGE_DIR . 'tests/snapshots/';
      $prefix = 'h-test-1';
      @mkdir("{$directory}{$prefix}", 0775, true);
      $Plant = static function (string $name, string $bytes) use ($directory, $prefix): void {
         file_put_contents("{$directory}{$prefix}/{$name}.snap", $bytes);
      };

      try {
         $Snapshot = new FileStorageSnapshot;

         // @ A planted object of a class outside the list never runs a magic method
         $Plant('gadget', serialize(new Snapshot_File_Gadget));
         Snapshot_File_Gadget::$woken = false;
         Snapshot_File_Gadget::$destroyed = false;
         $data = 'untouched';
         $restored = $Snapshot->restore("{$prefix}/gadget", $data);
         $type = get_debug_type($data);
         unset($data);
         yield assert(
            assertion: $restored === true
               && $type === '__PHP_Incomplete_Class'
               && Snapshot_File_Gadget::$woken === false
               && Snapshot_File_Gadget::$destroyed === false,
            description: "A planted object of a class outside the list comes back incomplete, with no __wakeup and no __destruct (got {$type})"
         );

         // @ An enum payload never reaches the autoloader — a positive control proves it would
         $probe = 'Bootgly\ACI\Tests\Snapshot_File_Probe_Enum';
         $payload = "{$probe}:A";
         $length = strlen($payload);
         $Plant('enum', "E:{$length}:\"{$payload}\";");
         $requested = [];
         $Recorder = static function (string $class) use (&$requested): void {
            $requested[] = $class;
         };
         spl_autoload_register($Recorder);
         try {
            $data = 'untouched';
            $restored = $Snapshot->restore("{$prefix}/enum", $data);
            $guarded = $requested;
            $requested = [];
            @unserialize((string) file_get_contents("{$directory}{$prefix}/enum.snap"), ['allowed_classes' => FileStorageSnapshot::CLASSES]);
            $control = $requested;
         }
         finally {
            spl_autoload_unregister($Recorder);
         }
         yield assert(
            assertion: $restored === false
               && $data === 'untouched'
               && $guarded === []
               && in_array($probe, $control, true) === true,
            description: 'An enum payload is refused before any autoloader sees its class (while a bare unserialize with allowed_classes hands it over)'
         );

         // @ Bytes that do not decode are no snapshot — the value stays
         $failures = [];
         foreach ([
            'garbage' => 'not-serialized',
            'truncated' => 'a:1:{s:1:"a";',
            'trailing' => serialize('ok') . 'X',
            // an allowed class in the custom format: a warning without the `unserialize(` prefix
            'unserializer' => 'C:8:"stdClass":13:{GARBAGE-SYNTH}',
            'empty' => '',
         ] as $name => $bytes) {
            $Plant($name, $bytes);
            $data = 'untouched';
            if ($Snapshot->restore("{$prefix}/{$name}", $data) !== false
               || $data !== 'untouched'
               || $Snapshot->restored !== false
               || $Snapshot->refused !== true) {
               $failures[] = $name;
            }
         }
         $data = 'untouched';
         $missing = $Snapshot->restore("{$prefix}/missing", $data) === false
            && $data === 'untouched'
            && $Snapshot->refused === false;
         $failed = implode(', ', $failures);
         yield assert(
            assertion: $failures === [] && $missing === true,
            description: "Garbage, truncated, trailing, custom-format and empty snapshots are refused, a missing one is not, and each restores false leaving the value alone (failed: [{$failed}])"
         );

         // @ A captured `false` is a snapshot
         $Plant('false', serialize(false));
         $data = 'untouched';
         yield assert(
            assertion: $Snapshot->restore("{$prefix}/false", $data) === true && $data === false,
            description: 'A captured false restores as false'
         );

         // @ Data and the allowed classes round-trip
         $Object = new stdClass;
         $Object->property = 'value';
         $values = [
            'string' => 'value',
            'array' => [1, [2, 'three']],
            'stdClass' => $Object,
            'DateTime' => new DateTime('2023-01-02 03:04:05'),
            'DateTimeImmutable' => new DateTimeImmutable('2023-01-02'),
         ];
         $failures = [];
         foreach ($values as $name => $value) {
            $data = null;
            if ($Snapshot->capture("{$prefix}/{$name}", $value) === false
               || $Snapshot->restore("{$prefix}/{$name}", $data) === false
               || $data != $value) {
               $failures[] = $name;
            }
         }
         $failed = implode(', ', $failures);
         yield assert(
            assertion: $failures === [],
            description: "Strings, arrays, stdClass and the date types round-trip (failed: [{$failed}])"
         );

         // @ An own class restores incomplete by default, whole once listed
         $Snapshot->capture("{$prefix}/value", new Snapshot_File_Value);
         $data = null;
         $Snapshot->restore("{$prefix}/value", $data);
         $default = get_debug_type($data);
         $Widened = new FileStorageSnapshot;
         $Widened->classes = [...FileStorageSnapshot::CLASSES, Snapshot_File_Value::class];
         $data = null;
         $Widened->restore("{$prefix}/value", $data);
         yield assert(
            assertion: $default === '__PHP_Incomplete_Class' && $data == new Snapshot_File_Value,
            description: "An own class comes back incomplete by default (a failed comparison, never a silent pass) and whole once it joins \$classes (default: {$default})"
         );

         // @ Every capture persists — the second one too — and assert() passes on each run
         $Named = new FileStorageSnapshot("{$prefix}/persist");
         $actual = 'v1';
         $expected = 'v1';
         $first = $Named->assert($actual, $expected);
         $actual = 'v1';
         $expected = 'v1';
         $second = $Named->assert($actual, $expected);
         $captured = $Named->capture("{$prefix}/persist", 'v2');
         $data = null;
         $Named->restore("{$prefix}/persist", $data);
         yield assert(
            assertion: $first === true && $second === true && $captured === true && $data === 'v2',
            description: 'A second capture overwrites the snapshot, and assert() passes on every run'
         );

         // @ A snapshot that is there but does not restore fails assert() and keeps its bytes —
         //   only a missing one is captured anew
         $Widen = static function (FileStorageSnapshot $Snapshot): FileStorageSnapshot {
            $Snapshot->classes = [...FileStorageSnapshot::CLASSES, Snapshot_File_Money::class];

            return $Snapshot;
         };
         $Widen(new FileStorageSnapshot)->capture("{$prefix}/money", new Snapshot_File_Money(1999));
         $baseline = file_get_contents("{$directory}{$prefix}/money.snap");
         $Money = $Widen(new FileStorageSnapshot("{$prefix}/money"));
         $Actual = new Snapshot_File_Money(2999);
         $Expected = $Actual;
         $typed = $Money->assert($Actual, $Expected);
         $Plant('corrupt', 'i:1;garbage');
         $Corrupt = new FileStorageSnapshot("{$prefix}/corrupt");
         $actual = 5;
         $expected = 5;
         $corrupt = $Corrupt->assert($actual, $expected);
         $Fresh = new FileStorageSnapshot("{$prefix}/fresh");
         $actual = 5;
         $expected = 5;
         $fresh = $Fresh->assert($actual, $expected);
         yield assert(
            assertion: $typed === false && $Money->refused === true
               && file_get_contents("{$directory}{$prefix}/money.snap") === $baseline
               && $corrupt === false && $Corrupt->refused === true
               && file_get_contents("{$directory}{$prefix}/corrupt.snap") === 'i:1;garbage'
               && str_starts_with($Corrupt->fail(5, 5)->format, 'Failed restoring the snapshot') === true
               && $fresh === true && $Fresh->refused === false
               && file_get_contents("{$directory}{$prefix}/fresh.snap") === serialize(5),
            description: 'A snapshot that does not restore — a typed property refusing an unlisted class, garbage — fails assert() and keeps its bytes; a missing one is captured'
         );

         // @ The Assertion API fails on that snapshot through every entry point — the value under
         //   test never stands in for the one it holds — and still passes on a sound one
         $failing = static function (callable $Chain): bool {
            try {
               $Chain();

               return false;
            }
            catch (AssertionError) {
               return true;
            }
         };
         // ! A failing Assertion rewrites the process-wide Vars debug settings: keep them
         $print = Vars::$print;
         $debug = Vars::$debug;
         $exit = Vars::$exit;
         $traces = Vars::$traces;
         $labels = Vars::$labels;
         $Plant('sound', serialize(5));
         $verdicts = [
            'assert(using:)' => $failing(static fn () => new Assertion(description: 'A')
               ->assert(actual: $Actual, expected: $Actual, using: $Widen(new FileStorageSnapshot("{$prefix}/money")))),
            'expect()->to->be()->assert(using:)' => $failing(static fn () => new Assertion(description: 'B')
               ->expect($Actual)->to->be($Actual)->assert(using: $Widen(new FileStorageSnapshot("{$prefix}/money")))),
            'Snapshot->restore()->assert()' => $failing(static function () use ($Actual, $Widen, $prefix): void {
               $Assertion = new Assertion(description: 'C');
               $Assertion->Snapshot = $Widen(new FileStorageSnapshot);
               $Assertion->restore("{$prefix}/money")->assert(actual: $Actual, expected: $Actual);
            }),
            'expect()->restore()->to->be()->assert()' => $failing(static function () use ($Actual, $Widen, $prefix): void {
               $Assertion = new Assertion(description: 'D');
               $Assertion->Snapshot = $Widen(new FileStorageSnapshot);
               $Assertion->expect($Actual)->restore("{$prefix}/money")->to->be($Actual)->assert();
            }),
         ];
         $controls = [
            'expect()->to->be()->assert(using:) on a missing snapshot' => $failing(static fn () => new Assertion(description: 'B0')
               ->expect(5)->to->be(5)->assert(using: new FileStorageSnapshot("{$prefix}/absent"))),
            'Snapshot->restore()->assert() on a sound snapshot' => $failing(static function () use ($prefix): void {
               $Assertion = new Assertion(description: 'C0');
               $Assertion->Snapshot = new FileStorageSnapshot;
               $Assertion->restore("{$prefix}/sound")->assert(actual: 7, expected: 5);
            }),
            'expect()->restore()->to->be()->assert() on a sound snapshot' => $failing(static function () use ($prefix): void {
               $Assertion = new Assertion(description: 'D0');
               $Assertion->Snapshot = new FileStorageSnapshot;
               $Assertion->expect(7)->restore("{$prefix}/sound")->to->be(5)->assert();
            }),
            // a refusal belongs to the restore that met it, never to a capture after it
            'capture()->assert() after a refused restore()' => $failing(static function () use ($prefix): void {
               $Recaptured = new FileStorageSnapshot;
               $data = null;
               $Recaptured->restore("{$prefix}/corrupt", $data);
               $Assertion = new Assertion(description: 'S0');
               $Assertion->Snapshot = $Recaptured;
               $Assertion->expect(5)->capture("{$prefix}/recaptured")->assert(expected: 5);
            }),
         ];
         Vars::$print = $print;
         Vars::$debug = $debug;
         Vars::$exit = $exit;
         Vars::$traces = $traces;
         Vars::$labels = $labels;
         $passed = [];
         foreach ($verdicts as $path => $failed) {
            if ($failed === false) {
               $passed[] = $path;
            }
         }
         $broken = [];
         foreach ($controls as $path => $failed) {
            if ($failed === true) {
               $broken[] = $path;
            }
         }
         $listed = implode(', ', $passed);
         $controlled = implode(', ', $broken);
         yield assert(
            assertion: $passed === [] && $broken === []
               && file_get_contents("{$directory}{$prefix}/money.snap") === $baseline,
            description: "assert(using:), an expectation with using: and a restore() into the Assertion — with or without expectations — all fail on a snapshot that does not restore, and pass on a sound or missing one and on a capture after it (passed: [{$listed}], broken controls: [{$controlled}])"
         );

         // @ A snapshot it cannot open is refused, never captured over: a directory at its place,
         //   and an unreadable file (unless run as root, which reads it)
         @mkdir("{$directory}{$prefix}/folder.snap");
         $Folder = new FileStorageSnapshot("{$prefix}/folder");
         $actual = 5;
         $expected = 5;
         $folder = $Folder->assert($actual, $expected) === false
            && $Folder->refused === true
            && is_dir("{$directory}{$prefix}/folder.snap") === true;
         $Plant('locked', serialize(1999));
         chmod("{$directory}{$prefix}/locked.snap", 0000);
         clearstatcache();
         $root = is_readable("{$directory}{$prefix}/locked.snap");
         $Locked = new FileStorageSnapshot("{$prefix}/locked");
         $actual = 5;
         $expected = 5;
         $locked = $root === true || (
            $Locked->assert($actual, $expected) === false
            && $Locked->refused === true
            && (fileperms("{$directory}{$prefix}/locked.snap") & 0777) === 0
         );
         chmod("{$directory}{$prefix}/locked.snap", 0644);
         $unlocked = file_get_contents("{$directory}{$prefix}/locked.snap") === serialize(1999);
         yield assert(
            assertion: $folder === true && $locked === true && $unlocked === true,
            description: 'A snapshot it cannot open — a directory at its place, an unreadable file — fails assert() and keeps its place'
         );

         // @ Only the snapshot file itself is read — never the `.php` or `/index.php` a missing name
         //   converts to — and a deprecation raised while an allowed class loads refuses nothing
         file_put_contents("{$directory}{$prefix}/converted.snap.php", '<?php return 7;');
         @mkdir("{$directory}{$prefix}/indexed.snap");
         file_put_contents("{$directory}{$prefix}/indexed.snap/index.php", '<?php return 7;');
         $data = 'untouched';
         $converted = $Snapshot->restore("{$prefix}/converted", $data) === false
            && $Snapshot->refused === false
            && $data === 'untouched';
         $data = 'untouched';
         $indexed = $Snapshot->restore("{$prefix}/indexed", $data) === false
            && $Snapshot->refused === true
            && $data === 'untouched';
         $legacy = 'Bootgly\ACI\Tests\Snapshot_File_Legacy';
         file_put_contents("{$directory}{$prefix}/legacy.php", <<<'PHP'
         <?php
         namespace Bootgly\ACI\Tests;

         trigger_error('A legacy class file deprecates something as it loads.', E_USER_DEPRECATED);

         final class Snapshot_File_Legacy
         {
            public int $cents = 1999;
         }
         PHP);
         $Loader = static function (string $class) use ($legacy, $directory, $prefix): void {
            if ($class === $legacy) {
               require "{$directory}{$prefix}/legacy.php";
            }
         };
         spl_autoload_register($Loader);
         try {
            $length = strlen($legacy);
            $Plant('legacy', "O:{$length}:\"{$legacy}\":1:{s:5:\"cents\";i:1999;}");
            $Legacy = new FileStorageSnapshot;
            $Legacy->classes = [...FileStorageSnapshot::CLASSES, $legacy];
            $data = null;
            $deprecated = $Legacy->restore("{$prefix}/legacy", $data) === true
               && $Legacy->refused === false
               && get_debug_type($data) === $legacy;
         }
         finally {
            spl_autoload_unregister($Loader);
         }
         // ! A deprecation raised as an allowed class wakes comes after unserialize() reported the
         //   trailing bytes: it never hides them
         $Wakeful = new FileStorageSnapshot;
         $Wakeful->classes = [...FileStorageSnapshot::CLASSES, Snapshot_File_Wakeful::class];
         $Plant('wakeful', serialize(new Snapshot_File_Wakeful));
         $data = null;
         $woken = $Wakeful->restore("{$prefix}/wakeful", $data) === true
            && $Wakeful->refused === false
            && $data instanceof Snapshot_File_Wakeful;
         $Plant('overslept', serialize(new Snapshot_File_Wakeful) . 'GARBAGE-SYNTH');
         $data = 'untouched';
         $overslept = $Wakeful->restore("{$prefix}/overslept", $data) === false
            && $Wakeful->refused === true
            && $data === 'untouched';
         yield assert(
            assertion: $converted === true && $indexed === true && $deprecated === true
               && $woken === true && $overslept === true,
            description: 'Only the snapshot file itself is read; a deprecation an allowed class raises as it loads or wakes refuses nothing, and never hides trailing bytes'
         );

         // @ A link at the snapshot's place is replaced, never written through
         $victim = "{$directory}{$prefix}/victim.txt";
         file_put_contents($victim, 'SYNTH-VICTIM');
         @unlink("{$directory}{$prefix}/link.snap");
         symlink($victim, "{$directory}{$prefix}/link.snap");
         $captured = $Snapshot->capture("{$prefix}/link", 'value');
         yield assert(
            assertion: $captured === true
               && file_get_contents($victim) === 'SYNTH-VICTIM'
               && is_link("{$directory}{$prefix}/link.snap") === false
               && is_file("{$directory}{$prefix}/link.snap") === true,
            description: 'A capture replaces a link at its place with a file and leaves the link target alone'
         );

         // @ An unnamed snapshot is still keyed by its file and its order there
         $First = new FileStorageSnapshot;
         $Second = new FileStorageSnapshot;
         $file = __FILE__;
         $index = (int) substr($First->key, strlen($file) + 1);
         $next = $index + 1;
         yield assert(
            assertion: $First->name === $file
               && $Second->name === $file
               && $First->key === "{$file}.{$index}"
               && $Second->key === "{$file}.{$next}",
            description: "An unnamed snapshot takes its file as its name and the next index as its key ({$First->key}, {$Second->key})"
         );
      }
      finally {
         foreach (['gadget', 'enum', 'garbage', 'truncated', 'trailing', 'empty', 'false', 'string', 'array', 'stdClass', 'DateTime', 'DateTimeImmutable', 'value', 'persist', 'link', 'money', 'corrupt', 'fresh', 'absent', 'sound', 'locked', 'legacy', 'unserializer', 'wakeful', 'overslept', 'recaptured'] as $name) {
            @unlink("{$directory}{$prefix}/{$name}.snap");
         }
         @chmod("{$directory}{$prefix}/locked.snap", 0644);
         @unlink("{$directory}{$prefix}/converted.snap.php");
         @unlink("{$directory}{$prefix}/indexed.snap/index.php");
         @rmdir("{$directory}{$prefix}/indexed.snap");
         @rmdir("{$directory}{$prefix}/folder.snap");
         @unlink("{$directory}{$prefix}/legacy.php");
         @unlink("{$directory}{$prefix}/victim.txt");
         if (is_dir("{$directory}{$prefix}") === true) {
            @rmdir("{$directory}{$prefix}");
         }
      }
   }
);

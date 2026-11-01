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


use function array_column;
use function array_keys;
use function assert;
use function class_alias;
use function class_exists;
use function class_parents;
use function constant;
use function file_put_contents;
use function get_object_vars;
use function implode;
use function ini_restore;
use function ini_set;
use function json_decode;
use function preg_match;
use function preg_replace;
use function realpath;
use function rmdir;
use function str_contains;
use function strtr;
use function substr_count;
use function unlink;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


enum DumpControlsModes: string
{
   case Safe = 'safe';
}

/**
 * M9 — a dump reaches the operator's terminal (`dump()`, `Vars::debug()`, the CLI Dumper atom),
 * and what it shows is often data a peer wrote: a JSON document's keys and values, the object
 * decoded from it, a class or a file name. Every control character a value, a key or a name
 * carries — C0, DEL, C1 — shows as the visible escape the logs use, and a byte that is not
 * UTF-8 shows as `?`: the dump is read, never obeyed.
 */
return new Test(
   description: 'It should show every control character a dump carries as visible text',
   test: function () {
      // ! Well-formed UTF-8 — checked by PCRE, so the spec runs without ext-mbstring too
      $Valid = static fn (string $text): bool => preg_match('//u', $text) === 1;
      // ! Anything a terminal acts on beyond the dump's own line feeds — C0, DEL, C1, stray bytes
      $Inert = static fn (string $dump): bool => preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $dump) === 0
         && $Valid($dump) === true;
      $Dumper = new Dumper('plain');

      $directory = Temporaries::reserve('dump-controls');
      // ! Valid UTF-8 — APFS refuses a file name that is not (EILSEQ)
      $file = "{$directory}/closure\e]0;pwned\x07\u{9B}2J.php";

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes whatever
         //   `mbstring.substitute_character` holds (the polyfill always writes `?`)
         ini_set('mbstring.substitute_character', '63');

         // @ String values — every control shows as its visible escape, none stays raw: a complete
         //   SGR and the line controls (CR, LF, TAB) too
         $values = [
            ["osc\e]52;c;SGVsbG8=\x07", "'osc\\u001b]52;c;SGVsbG8=\\u0007'"],
            ["csi\e[2J", "'csi\\u001b[2J'"],
            ["sgr\e[8mhidden\e[0m", "'sgr\\u001b[8mhidden\\u001b[0m'"],
            ["cr\rspoof\nline\tx", "'cr\\rspoof\\nline\\tx'"],
            ["c1\u{9D}52;c;SGVsbG8=\u{9C}", "'c1\\u009d52;c;SGVsbG8=\\u009c'"],
            ["c1\u{9B}2J", "'c1\\u009b2J'"],
            ["nul\x00x", "'nul\\u0000x'"],
            ["bel\x07", "'bel\\u0007'"],
            ["vt\x0B", "'vt\\u000b'"],
            ["del\x7F", "'del\\u007f'"],
         ];
         $shown = true;
         foreach ($values as [$value, $expected]) {
            $dumped = $Dumper->dump($value);
            $shown = $shown && $dumped === $expected && $Inert($dumped);
         }

         yield assert(
            assertion: $shown,
            description: 'String values show OSC 52, CSI, SGR, CR/LF/TAB, C1 OSC/CSI, NUL, BEL, VT and DEL as `\u001b` `\r` `\n` `\t` `\u009d` `\u009b` `\u0000` `\u0007` `\u000b` `\u007f` — no raw byte'
         );

         // @ The quote and the backslash — escaped before the controls, so a literal `\u001b`
         //   never reads as an ESC
         $literal = $Dumper->dump('lit\u001b');
         $real = $Dumper->dump("lit\e");

         yield assert(
            assertion: $Dumper->dump("q'uote") === "'q\\'uote'"
               && $Dumper->dump('back\slash') === "'back\\\\slash'"
               && $literal === "'lit\\\\u001b'"
               && $real === "'lit\\u001b'"
               && $literal !== $real,
            description: 'The quote and the backslash escape first — a literal `\u001b` stays distinguishable from a real ESC'
         );

         // @ String keys — the same quoting as values
         $expected = <<<'DUMP'
         array:3 [
            'k\u001b]52;c;SGVsbG8=\u0007' => 1
            'c1\u009d52;c;x\u009c' => 2
            7 => 'seven'
         ]
         DUMP;

         yield assert(
            assertion: $Dumper->dump(["k\e]52;c;SGVsbG8=\x07" => 1, "c1\u{9D}52;c;x\u{9C}" => 2, 7 => 'seven']) === $expected,
            description: 'String array keys show their controls escaped'
         );

         // @ Property names a peer wrote — a JSON document decoded into an object; a raw SGR would
         //   restyle the dump, a raw CR/LF would forge a line of it
         $Document = json_decode('{"k\u001b]52;c;SGVsbG8=\u0007":1,"c1\u009d52;c;x\u009c":2,"mid\u0000nul":3,"sgr\u001b[8mx":4,"cr\rk\nl\tm":5}');
         $expected = <<<'DUMP'
         stdClass {
            +k\u001b]52;c;SGVsbG8=\u0007: 1
            +c1\u009d52;c;x\u009c: 2
            +mid\u0000nul: 3
            +sgr\u001b[8mx: 4
            +cr\rk\nl\tm: 5
         }
         DUMP;

         yield assert(
            assertion: $Document instanceof stdClass
               && str_contains(implode('|', array_keys(get_object_vars($Document))), "\e]52;c;") === true
               && $Dumper->dump($Document) === $expected,
            description: 'json_decode() property names show ESC, BEL, C1, NUL, SGR and CR/LF/TAB escaped'
         );

         // ! Names a terminal would obey — a PHP identifier takes any byte 0x80–0xFF, so a class,
         //   a property or an enum case can carry a C1 CSI (U+009B) or a stray byte
         if (class_exists("Bootgly\\ABI\\Debugging\\Data\\Vars\\DumpControlsHeir\u{9B}2J", false) === false) {
            eval(strtr(<<<'PHP'
            namespace Bootgly\ABI\Debugging\Data\Vars;

            class DumpControlsOrigin%C1%31m
            {
               private int $hidden = 1;
            }

            final class DumpControlsHeir%C1%2J extends DumpControlsOrigin%C1%31m
            {
               public int $shown%C1%2J = 2;
               public null|object $self = null;
            }

            final class DumpControlsStray%BYTE%2J
            {
               public int $p%BYTE% = 1;
            }

            enum DumpControlsCases%C1%m: string
            {
               case Hostile%C1%2J = "v\e]0;t\x07";
            }
            PHP, ['%C1%' => "\u{9B}", '%BYTE%' => "\x9B"]));
         }

         // @ Bytes that are not UTF-8 — each stray byte shows as `?`, so the dump stays valid UTF-8
         //   (inert on a UTF-8 terminal, as `Controls::escape()` states)
         $bytes = "x\x9B2J caf\u{E9} \xFF";
         $key = "k\x9B2J";
         $name = "p\x9B2J";
         $stray = "Bootgly\\ABI\\Debugging\\Data\\Vars\\DumpControlsStray\x9B2J";
         $Stray = (object) [$name => 1];
         $expected = <<<'DUMP'
         array:4 [
            'value' => 'x?2J café ?'
            'k?2J' => 1
            'name' => stdClass {
               +p?2J: 1
            }
            'class' => Bootgly\ABI\Debugging\Data\Vars\DumpControlsStray?2J {
               +p?: 1
            }
         ]
         DUMP;

         yield assert(
            assertion: $Valid($bytes) === false
               && $Valid($key) === false
               && $Valid($name) === false
               && $Valid($stray) === false
               && $Dumper->dump(['value' => $bytes, $key => 1, 'name' => $Stray, 'class' => new $stray]) === $expected,
            description: 'A value, a key, a property name and a class name that are not UTF-8 show `?` per stray byte'
         );

         // @ An anonymous class — PHP's `::class` embeds a NUL and the declaring file
         $Anonymous = new class {
            public null|object $self = null;
            public int $p = 1;
         };
         $Anonymous->self = $Anonymous;
         $expected = <<<'DUMP'
         class@anonymous {
            +self: class@anonymous *RECURSION*
            +p: 1
         }
         DUMP;

         yield assert(
            assertion: str_contains($Anonymous::class, "\x00") === true
               && str_contains($Anonymous::class, '1.8-dump-controls.Test.php') === true
               && $Dumper->dump($Anonymous) === $expected,
            description: 'An anonymous class is headed exactly `class@anonymous {` — no NUL, no path, in its recursion mark too'
         );

         // @ Enums — a plain backed enum unchanged, a hostile enum and case name escaped
         $Hostile = constant("Bootgly\\ABI\\Debugging\\Data\\Vars\\DumpControlsCases\u{9B}m::Hostile\u{9B}2J");

         yield assert(
            assertion: $Dumper->dump(DumpControlsModes::Safe) === 'Bootgly\ABI\Debugging\Data\Vars\DumpControlsModes::Safe = \'safe\''
               && $Dumper->dump($Hostile) === 'Bootgly\ABI\Debugging\Data\Vars\DumpControlsCases\u009bm::Hostile\u009b2J = \'v\u001b]0;t\u0007\'',
            description: 'A backed enum dumps unchanged; an enum and a case named with a C1 CSI show `\u009b`'
         );

         // @ A closure — located by a file whose name carries ESC, BEL and a C1 CSI
         file_put_contents($file, "<?php\nreturn static fn (): int => 1;\n");
         $Closure = require $file;
         // ! PHP records the file with symlinks resolved (macOS: /var → /private/var)
         $resolved = realpath($directory);

         yield assert(
            assertion: str_contains($file, "\e]0;pwned\x07") === true
               && $Dumper->dump($Closure) === "Closure ({$resolved}/closure\\u001b]0;pwned\\u0007\\u009b2J.php:2)",
            description: 'A closure shows its file path escaped — `\u001b`, `\u0007`, `\u009b`'
         );

         // @ Names a class declares — its own, a property, the recursion mark, a shadowed
         //   ancestor private's origin
         $heir = "Bootgly\\ABI\\Debugging\\Data\\Vars\\DumpControlsHeir\u{9B}2J";
         $Heir = new $heir;
         $Heir->self = $Heir;
         $expected = <<<'DUMP'
         Bootgly\ABI\Debugging\Data\Vars\DumpControlsHeir\u009b2J {
            +shown\u009b2J: 2
            +self: Bootgly\ABI\Debugging\Data\Vars\DumpControlsHeir\u009b2J *RECURSION*
            -hidden: 1 (DumpControlsOrigin\u009b31m)
         }
         DUMP;

         yield assert(
            assertion: $Dumper->dump($Heir) === $expected,
            description: 'A class, a property, a recursion mark and an ancestor origin named with a C1 CSI show `\u009b`'
         );

         // ! An anonymous ancestor — `class_alias()` names an anonymous class, so a class can
         //   extend one: five generations, each with its own private `$hidden`; the first, the
         //   second (extending the first) and the fourth anonymous
         if (class_exists(DumpControlsDescendant::class, false) === false) {
            $Root = new class {
               private int $hidden = 1;
            };
            class_alias($Root::class, DumpControlsRoot::class);
            // ! An anonymous class extending an anonymous one — its PHP name embeds the
            //   parent's NUL-and-path name too
            $Graft = new class extends DumpControlsRoot {
               private int $hidden = 0;
            };
            class_alias($Graft::class, DumpControlsGraft::class);
            eval(<<<'PHP'
            namespace Bootgly\ABI\Debugging\Data\Vars;

            class DumpControlsMiddle extends DumpControlsGraft
            {
               private int $hidden = 2;
            }
            PHP);
            $Branch = new class extends DumpControlsMiddle {
               private int $hidden = 3;
            };
            class_alias($Branch::class, DumpControlsBranch::class);
            eval(<<<'PHP'
            namespace Bootgly\ABI\Debugging\Data\Vars;

            final class DumpControlsDescendant extends DumpControlsBranch
            {
               private int $hidden = 4;
            }
            PHP);
         }

         // @ The origin of an anonymous ancestor's private — `class@anonymous`, or
         //   `<Parent>@anonymous` when that ancestor extends a class: PHP's own name for it
         //   embeds a NUL and the declaring file
         $Descendant = new DumpControlsDescendant;
         $ancestors = implode('|', class_parents($Descendant));
         $expected = <<<'DUMP'
         Bootgly\ABI\Debugging\Data\Vars\DumpControlsDescendant {
            -hidden: 4
            -hidden: 3 (Bootgly\ABI\Debugging\Data\Vars\DumpControlsMiddle@anonymous)
            -hidden: 2 (DumpControlsMiddle)
            -hidden: 0 (Bootgly\ABI\Debugging\Data\Vars\DumpControlsRoot@anonymous)
            -hidden: 1 (class@anonymous)
         }
         DUMP;

         yield assert(
            assertion: substr_count($ancestors, "@anonymous\x00") === 3
               && str_contains($ancestors, '1.8-dump-controls.Test.php') === true
               && $Dumper->dump($Descendant) === $expected,
            description: 'A private an anonymous ancestor declares shows its origin as `class@anonymous` or `<Parent>@anonymous` — no NUL, no path'
         );

         // @ Decorated — once the palette's complete SGR sequences are stripped, nothing a
         //   terminal obeys is left: the colors are the only escapes in the dump
         $Everything = [
            'values' => array_column($values, 0),
            'keys' => ["k\e]52;c;SGVsbG8=\x07" => 1, "c1\u{9D}52;c;x\u{9C}" => 2, $key => 3],
            'document' => $Document,
            'stray' => [$bytes, $Stray, new $stray],
            'anonymous' => $Anonymous,
            'enum' => $Hostile,
            'closure' => $Closure,
            'heir' => $Heir,
            'descendant' => $Descendant,
         ];
         $plain = $Dumper->dump($Everything);
         $decorated = new Dumper('bootgly')->dump($Everything);
         $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $decorated);

         yield assert(
            assertion: str_contains($decorated, "\e[") === true
               && $decorated !== $plain
               && $Inert($stripped) === true
               && $stripped === $plain,
            description: 'The `bootgly` theme decorates with SGR only — stripped of it, the dump is the plain one: no ESC, no C1, no stray byte'
         );
      }
      finally {
         ini_restore('mbstring.substitute_character');
         @unlink($file);
         @rmdir($directory);
      }
   }
);

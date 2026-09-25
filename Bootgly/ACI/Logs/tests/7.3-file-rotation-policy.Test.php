<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Handlers\File\Rotation;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * The rotation policy: what is due, where archives go, and what a failure does.
 *
 * `keep: 0` keeps no archive (LOGS-9); an empty file is never archived; the
 * first free archive number is filled, so a gap in the chain costs no
 * archive; a rotation that cannot complete stops where it failed — nothing
 * raises, the record is written to the active file and the failure is
 * reported once.
 */
return new Test(
   description: 'Rotation fills the first free archive, keeps none with keep 0, never archives an empty file, and a failed rotation keeps writing without raising',
   test: function () {
      $dir = Temporaries::reserve('logs-rotation-policy');
      $path = "$dir/app.log";
      $yesterday = strtotime('today') - 3600;

      // ! Empty a directory (and remove it when asked)
      $purge = static function (string $folder, bool $self = false) use (&$purge): void {
         foreach ((array) @scandir($folder) as $name) {
            if ($name === '.' || $name === '..' || $name === false) {
               continue;
            }
            $entry = "$folder/$name";
            if (is_dir($entry) === true && is_link($entry) === false) {
               $purge($entry, true);
            }
            else {
               @unlink($entry);
            }
         }
         if ($self === true) {
            @rmdir($folder);
         }
      };
      $record = static fn (string $message): Record => new Record(Levels::Info, 'policy', $message);
      $read = static fn (string $file): string => trim((string) @file_get_contents($file));
      $chain = static function () use ($path, $read): array {
         $chain = [];
         for ($index = 1; $index <= 7; $index++) {
            $chain[$index] = $read("$path.$index");
         }

         return $chain;
      };

      try {
         // @@ G) keep 0 keeps no archive (LOGS-9)
         file_put_contents($path, "G-yesterday\n");
         touch($path, $yesterday);
         $G = (new File($path, Rotation: new Rotation(size: 0, daily: true, keep: 0)))->handle($record('G-today'));
         yield assert(
            assertion: $G === true
               && file_exists("$path.1") === false
               && str_contains($read($path), 'G-today')
               && str_contains($read($path), 'G-yesterday') === false,
            description: 'keep 0 keeps no archive: the ended day is dropped, not moved to .1'
         );
         $purge($dir);

         // @@ H) An empty file from an ended day is written to, never archived
         touch($path, $yesterday);
         $H = (new File($path))->handle($record('H-today'));
         yield assert(
            assertion: $H === true
               && file_exists("$path.1") === false
               && str_contains($read($path), 'H-today'),
            description: 'an empty file is never archived — the new day is written to it'
         );
         $purge($dir);

         // @@ I) An archive slot that cannot be freed: the rotation stops, nothing
         //    raises, records go to the active file and the failure is reported once
         for ($index = 1; $index <= 6; $index++) {
            file_put_contents("$path.$index", "a$index\n");
         }
         mkdir("$path.7");
         file_put_contents("$path.7/kept", 'kept');
         file_put_contents($path, "I-before\n");
         $Obstacle = new File($path, Rotation: new Rotation(size: 1, daily: false, keep: 7));
         $escape = null;
         $I = [];
         try {
            $I[] = $Obstacle->handle($record('I-first'));
            $I[] = $Obstacle->handle($record('I-second'));
         }
         catch (Throwable $Throwable) {
            $escape = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         $Reflection = new ReflectionClass(File::class);
         $reported = $Reflection->hasProperty('reported')
            ? (array) $Reflection->getProperty('reported')->getValue()
            : [];
         $failures = array_values(array_filter(
            array_keys($reported),
            static fn (string $key): bool => str_starts_with($key, "$path|")
         ));
         $active = $read($path);
         yield assert(
            assertion: $escape === null && $I === [true, true]
               && array_slice($chain(), 0, 6, true) === [1 => 'a1', 2 => 'a2', 3 => 'a3', 4 => 'a4', 5 => 'a5', 6 => 'a6']
               && file_get_contents("$path.7/kept") === 'kept'
               && str_contains($active, 'I-before') && str_contains($active, 'I-first') && str_contains($active, 'I-second')
               && $failures === ["$path|the file could not be rotated"],
            description: 'a rotation that cannot free the oldest slot stops: no throwable, archives byte-identical, both records in the active file, the failure reported once '
               . '(escape: ' . var_export($escape, true) . ', reported: ' . json_encode($failures) . ')'
         );
         $purge($dir);

         // @@ J) A gap in the chain is filled: the archives above it stay
         foreach ([1, 2, 3, 5, 6, 7] as $index) {
            file_put_contents("$path.$index", "a$index\n");
         }
         file_put_contents($path, "J-yesterday\n");
         touch($path, $yesterday);
         $J = (new File($path))->handle($record('J-today'));
         yield assert(
            assertion: $J === true
               && $chain() === [1 => 'J-yesterday', 2 => 'a1', 3 => 'a2', 4 => 'a3', 5 => 'a5', 6 => 'a6', 7 => 'a7']
               && file_exists("$path.8") === false
               && str_contains($read($path), 'J-today'),
            description: 'a rotation fills the first free archive number: the archives above the gap are kept, none is dropped'
         );
         $purge($dir);

         // @@ K) check(): the due boundaries, and names it never follows
         $Size = new Rotation(size: 10, daily: false);
         $Daily = new Rotation(size: 0, daily: true);
         $checks = [];
         file_put_contents($path, str_repeat('k', 9));
         $checks['below the cap'] = $Size->check($path);
         file_put_contents($path, str_repeat('k', 10));
         $checks['at the cap'] = $Size->check($path);
         $checks['size 0 disables the cap'] = (new Rotation(size: 0, daily: false))->check($path);
         touch($path, strtotime('today') - 1);
         $checks['last second of yesterday'] = $Daily->check($path);
         touch($path, strtotime('today'));
         $checks['first second of today'] = $Daily->check($path);
         file_put_contents($path, '');
         touch($path, $yesterday);
         $checks['empty'] = $Daily->check($path);
         file_put_contents("$dir/due.log", 'due');
         touch("$dir/due.log", $yesterday);
         symlink("$dir/due.log", "$dir/link.log");
         $checks['link to a due file'] = $Daily->check("$dir/link.log");
         mkdir("$dir/folder.log");
         touch("$dir/folder.log", $yesterday);
         $checks['directory'] = $Daily->check("$dir/folder.log");
         $checks['absent'] = $Daily->check("$dir/absent.log");
         $checks['due (control)'] = $Daily->check("$dir/due.log");
         file_put_contents($path, 'K-today');
         file_put_contents("$path.1", 'K-archive');
         $Daily->rotate($path);
         $untouched = $read($path) === 'K-today' && $read("$path.1") === 'K-archive' && file_exists("$path.2") === false;
         yield assert(
            assertion: $checks === [
               'below the cap' => false,
               'at the cap' => true,
               'size 0 disables the cap' => false,
               'last second of yesterday' => true,
               'first second of today' => false,
               'empty' => false,
               'link to a due file' => false,
               'directory' => false,
               'absent' => false,
               'due (control)' => true,
            ] && $untouched,
            description: 'check() is due at the cap and on an ended day, never for an empty file, a link, a directory or an absent name; rotate() on a file not due touches nothing '
               . json_encode($checks)
         );

         $purge($dir);

         // @@ L) The first moments of a day are waited out before the day's
         //    rotation (a coarse filesystem clock may still stamp a file created
         //    right after midnight with the day before) — and only then
         $midnight = (float) strtotime('today');
         $Settle = new class (size: 0, daily: true) extends Rotation {
            public float $offset = 0.0;
            public float $origin = 0.0;

            protected float $now {
               get => $this->offset + (microtime(true) - $this->origin);
            }
         };
         file_put_contents($path, "L-yesterday\n");
         touch($path, $yesterday);
         $settle = [];
         foreach (['1 ms into the day' => 0.001, 'half a second into it' => 0.5, 'half a second before it' => -0.5] as $moment => $offset) {
            $Settle->offset = $midnight + $offset;
            $Settle->origin = microtime(true);
            $due = $Settle->check($path);
            $settle[$moment] = [$due, microtime(true) - $Settle->origin];
         }
         // # A midnight a clock change repeats (Asia/Gaza, 2020-10-24): the true start
         //   of the day is waited out, the repeated 00:00 an hour later is not a new day
         $zone = date_default_timezone_get();
         date_default_timezone_set('Asia/Gaza');
         try {
            foreach ([
               'the true start of a repeated-midnight day' => '2020-10-24T00:00:00+03:00',
               'its repeated midnight' => '2020-10-24T00:00:00+02:00',
            ] as $moment => $instant) {
               $Settle->offset = (float) strtotime($instant) + 0.001;
               $Settle->origin = microtime(true);
               $due = $Settle->check($path);
               $settle[$moment] = [$due, microtime(true) - $Settle->origin];
            }
         }
         finally {
            date_default_timezone_set($zone);
         }
         // # A clock that never moves (a subclass's): the wait still ends
         $Frozen = new class (size: 0, daily: true) extends Rotation {
            public float $frozen = 0.0;

            protected float $now {
               get => $this->frozen;
            }
         };
         $Frozen->frozen = $midnight + 0.001;
         $started = microtime(true);
         $due = $Frozen->check($path);
         $settle['a frozen clock 1 ms into the day'] = [$due, microtime(true) - $started];
         yield assert(
            assertion: $settle['1 ms into the day'][0] === true && $settle['1 ms into the day'][1] >= 0.04
               && $settle['half a second into it'][0] === true && $settle['half a second into it'][1] < 0.03
               && $settle['half a second before it'][0] === false && $settle['half a second before it'][1] < 0.03
               && $settle['the true start of a repeated-midnight day'][0] === true
               && $settle['the true start of a repeated-midnight day'][1] >= 0.04
               && $settle['its repeated midnight'][0] === true && $settle['its repeated midnight'][1] < 0.03
               && $settle['a frozen clock 1 ms into the day'][0] === true
               && $settle['a frozen clock 1 ms into the day'][1] >= 0.04
               && $settle['a frozen clock 1 ms into the day'][1] < 0.5,
            description: 'the day\'s rotation waits out the first 50 ms of each day, and only them — a midnight a clock change repeats is not a new day, and a clock that never moves still ends the wait: '
               . json_encode($settle)
         );
         $purge($dir);

         // @@ M) A second name left by a creator killed in its link window (its
         //    own temporary pattern, same inode) is an orphan and is removed —
         //    the file is written again, due or not, by any writer; a second name
         //    elsewhere on a due file is rotated away by an unprivileged writer and
         //    refused by a privileged one (that name may be anybody's); a file this
         //    identity cannot write is refused (root can: it rotates it, as at HEAD)
         $root = posix_geteuid() === 0;
         file_put_contents($path, '');
         link($path, "$dir/.app.log.0123456789abcdef");
         $orphan = (new File($path))->handle($record('M-after-orphan'));
         clearstatcache();
         $healed = $orphan === true && file_exists("$dir/.app.log.0123456789abcdef") === false
            && (int) (stat($path)['nlink'] ?? 0) === 1 && str_contains($read($path), 'M-after-orphan');
         $purge($dir);
         file_put_contents($path, "M-second\n");
         touch($path, $yesterday);
         link($path, "$dir/elsewhere");
         $second = (new File($path))->handle($record('M-after-second'));
         $secondKept = $root
            ? $second === false && $read($path) === 'M-second' && file_exists("$path.1") === false
            : $second === true && $read("$path.1") === 'M-second' && str_contains($read($path), 'M-after-second');
         $purge($dir);
         file_put_contents($path, "M-readonly\n");
         chmod($path, 0444);
         touch($path, $yesterday);
         $readonly = (new File($path))->handle($record('M-after-readonly'));
         $readonlyKept = $root
            ? $readonly === true && $read("$path.1") === 'M-readonly' && str_contains($read($path), 'M-after-readonly')
            : $readonly === false && $read($path) === 'M-readonly' && file_exists("$path.1") === false;
         yield assert(
            assertion: $healed && $secondKept && $readonlyKept,
            description: 'a creator\'s orphaned temporary name is removed and the file written again; a second name elsewhere is rotated away by an unprivileged writer and refused by a privileged one; an unwritable file is refused '
               . json_encode(['root' => $root, 'healed' => $healed, 'second' => $secondKept, 'readonly' => $readonlyKept])
         );
         $purge($dir);

         // @@ N) check() is the policy: a custom rotation that overrides it is honored
         $Custom = new class extends Rotation {
            public function check (string $path): bool
            {
               return str_contains((string) @file_get_contents($path), 'N-rotate-me');
            }
         };
         file_put_contents($path, "N-rotate-me\n");
         $N = (new File($path, Rotation: $Custom))->handle($record('N-after'));
         yield assert(
            assertion: $N === true && $read("$path.1") === 'N-rotate-me' && str_contains($read($path), 'N-after')
               && str_contains($read($path), 'N-rotate-me') === false,
            description: 'a rotation that overrides check() is honored by the handler and by rotate()'
         );

         // @@ Pins: every filesystem call of Rotation is suppressed, Rotation never
         //    names File, and a creator locks its file before linking it into place
         $Tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass(Rotation::class))->getFileName()));
         $significant = array_values(array_filter(
            $Tokens,
            static fn (array|string $Token): bool => is_array($Token) === false
               || in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
         $unsuppressed = [];
         $calls = 0;
         $named = 0;
         foreach ($significant as $index => $Token) {
            if (is_array($Token) && $Token[0] === T_STRING && in_array($Token[1], ['lstat', 'unlink', 'rename'], true)
               && ($significant[$index + 1] ?? null) === '(') {
               $calls++;
               if (($significant[$index - 1] ?? null) !== '@') {
                  $unsuppressed[] = "{$Token[1]}@{$Token[2]}";
               }
            }
            if (is_array($Token) && (
               ($Token[0] === T_STRING && $Token[1] === 'File')
               || (in_array($Token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                  && trim($Token[1], '\\') === 'Bootgly\\ACI\\Logs\\Handlers\\File'
                  && (($significant[$index - 1][0] ?? null) !== T_NAMESPACE))
            )) {
               $named++;
            }
         }
         $Tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass(File::class))->getFileName()));
         $significant = array_values(array_filter(
            $Tokens,
            static fn (array|string $Token): bool => is_array($Token) === false
               || in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
         $flock = null;
         $link = null;
         $inside = false;
         $depth = 0;
         foreach ($significant as $index => $Token) {
            if ($inside === false) {
               $inside = is_array($Token) && $Token[0] === T_STRING && $Token[1] === 'open'
                  && is_array($significant[$index - 1] ?? null) && $significant[$index - 1][0] === T_FUNCTION;
               continue;
            }
            if ($Token === '{') {
               $depth++;
            }
            else if ($Token === '}' && --$depth === 0) {
               break;
            }
            if (is_array($Token) && $Token[0] === T_STRING && ($significant[$index + 1] ?? null) === '(') {
               if ($Token[1] === 'flock' && $flock === null
                  && is_array($significant[$index + 2] ?? null) && $significant[$index + 2][1] === '$made') {
                  $flock = $index;
               }
               if ($Token[1] === 'link' && $link === null) {
                  $link = $index;
               }
            }
         }
         // # A privileged writer never blocks on an inode with a second name: lock() polls with LOCK_NB
         $nonblocking = false;
         $polled = false;
         $inside = false;
         $depth = 0;
         foreach ($significant as $index => $Token) {
            if ($inside === false) {
               $inside = is_array($Token) && $Token[0] === T_STRING && $Token[1] === 'lock'
                  && is_array($significant[$index - 1] ?? null) && $significant[$index - 1][0] === T_FUNCTION;
               continue;
            }
            if ($Token === '{') {
               $depth++;
            }
            else if ($Token === '}' && --$depth === 0) {
               break;
            }
            if (is_array($Token) && $Token[0] === T_STRING && $Token[1] === 'LOCK_NB') {
               $nonblocking = true;
            }
            if (is_array($Token) && $Token[0] === T_STRING && $Token[1] === 'usleep') {
               $polled = true;
            }
         }
         yield assert(
            assertion: $calls >= 6 && $unsuppressed === [] && $named === 0
               && $flock !== null && $link !== null && $flock < $link
               && $nonblocking && $polled,
            description: 'pins: every lstat/unlink/rename in Rotation carries @ (' . $calls . ' calls, unsuppressed: ' . json_encode($unsuppressed) . '), '
               . 'Rotation never names File, open() locks the file it creates before linking it into place, and a privileged lock() polls with LOCK_NB on an inode with a second name'
         );
      }
      finally {
         $purge($dir, true);
      }
   }
);

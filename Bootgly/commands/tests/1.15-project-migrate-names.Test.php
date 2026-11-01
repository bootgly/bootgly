<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const SQLITE3_ASSOC;
use const SQLITE3_OPEN_READONLY;
use const SQLITE3_TEXT;
use function array_diff;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function dirname;
use function extension_loaded;
use function fclose;
use function feof;
use function file_put_contents;
use function fread;
use function function_exists;
use function getenv;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function microtime;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function scandir;
use function str_contains;
use function str_replace;
use function stream_select;
use function stream_set_blocking;
use function substr;
use function unlink;
use function usleep;
use RuntimeException;
use SQLite3;
use SQLite3Exception;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Fieldset;


/**
 * `project <Name> migrate` (and `seed`) show what the database holds as
 * text and never obey it. A temporary kit is run through its REAL launcher,
 * with one project whose SQLite migration history holds a row `migrate`
 * never wrote — as an attacker with write access to the database would plant
 * it: its name carries an OSC 52 clipboard write, a CSI erase, an SGR that
 * conceals what follows, an OSC 52 in its C1 form (U+009D … U+009C), a C1 CSI
 * (U+009B), Output markup, a TAB and an LF that would start a forged row. The
 * project's own migration file has a benign name, so that row is "DB only".
 *
 * - The history holds those bytes raw (read back with ext-sqlite3, not the
 *   driver under test), and shown raw in a box row — the content line
 *   `status` built before — they drive the box. Without this control every
 *   "nothing raw" leg would also pass against a history holding nothing
 *   hostile.
 * - `migrate status` (its Remove row) and `migrate sync` (its Delete first
 *   row; the confirmation reads EOF, so nothing is written) show the name as
 *   text: a control is the escape `json_encode()` writes (`\u001b`, `\u009d`,
 *   `\t`, `\n`), the markup loses its introducer, the box keeps its rows.
 * - `migrate down 1` fails on that row, and the catch-all Alert shows the
 *   Runner's message — which quotes the name — as text.
 * - A local migration file whose name carries the controls shows as text in
 *   the Next and Add first rows too.
 * - A history the driver cannot read — `_bootgly_migrations` is a view over a
 *   dropped table whose name carries the payload — makes SQLite quote that
 *   name in its error: the catch-alls of `migrate status` and `seed run` show
 *   that driver message as text.
 *
 * Every output holds no ESC but the one an SGR opens — the colours the
 * command writes itself — and neither the payload's conceal SGR nor the SGR 31
 * its markup would emit, no C1 code point, no BEL and no TAB; the detector is
 * checked first against each of those. A catch-all keeps a message's LF
 * (`clean(breaks: true)`), so the Alert legs read the line before it.
 */
return new Test(
   description: '`project <Name> migrate` and `seed` show a migration name and a driver message from the database as text: visible escapes, no C1, no live markup, no forged row',
   // ? Nothing to spawn, or nothing to hold the history
   skip: function_exists('proc_open') === false || extension_loaded('sqlite3') === false,
   test: function () {
      // ! The DB-only name, as the history holds it
      $stored = "2026_01_01_000000_x\e]52;c;cHduZWQ=\x07\e[2J\e[8m\u{9D}52;c;SGk=\u{9C}\u{9B}H@#red:FORGED@;\tTAB\nApplied      999";
      // ! What a one-line display shows of it — and the line a multi-line
      //   message (a catch-all keeps the LF) shows before the break
      $shown = '2026_01_01_000000_x\u001b]52;c;cHduZWQ=\u0007\u001b[2J\u001b[8m\u009d52;c;SGk=\u009c\u009bH#red:FORGED;\tTAB\nApplied      999';
      $first = '2026_01_01_000000_x\u001b]52;c;cHduZWQ=\u0007\u001b[2J\u001b[8m\u009d52;c;SGk=\u009c\u009bH#red:FORGED;\tTAB';
      // ! The project's own migration — "Local only", so the stored row is "DB only"
      $benign = '20260601000000_create_accounts';
      // ! A local migration whose file name carries the controls — it sorts
      //   first, so it is the Next / Add first row
      $local = "20260101000000_y\e]52;c;SGk=\x07\e[2J\u{9B}H@#red:LOCAL@;\nDB only      0";
      $listed = '20260101000000_y\u001b]52;c;SGk=\u0007\u001b[2J\u009bH#red:LOCAL;\nDB only      0';
      // ! The table a broken history view reads, once dropped — SQLite names it in its error
      $dropped = "ghost\e]52;c;cHduZWQ=\x07\e[2J\e[8m\u{9D}52;c;SGk=\u{9C}\u{9B}H@#red:DRIVER@;\tTAB";
      $reported = 'ghost\u001b]52;c;cHduZWQ=\u0007\u001b[2J\u001b[8m\u009d52;c;SGk=\u009c\u009bH#red:DRIVER;\tTAB';

      // ! A human run (agent markers would change the contract), wide enough
      //   that no Alert matched below is cropped
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $environment['COLUMNS'] = '400';

      $directory = Temporaries::reserve('project-migrate-names');
      $kit = "{$directory}/kit";
      $project = "{$kit}/projects/Ledger";
      $migrations = "{$project}/database/migrations";
      $database = "{$project}/database/history.sqlite";

      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      $Write = static function (string $file, string $contents): void {
         $dir = dirname($file);
         if (is_dir($dir) === false) {
            mkdir($dir, 0o700, true);
         }
         file_put_contents($file, $contents);
      };
      // ! The history as ext-sqlite3 reads it — not through the driver under test
      $Read = static function () use ($database): array {
         $History = new SQLite3($database, SQLITE3_OPEN_READONLY);
         $History->enableExceptions(true);
         $Result = $History->query('SELECT "migration" FROM "_bootgly_migrations" ORDER BY "migration"');
         $names = [];
         // @@
         while ($Result !== false && ($row = $Result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $names[] = $row['migration'];
         }
         $History->close();

         // :
         return $names;
      };
      // ! The kit's REAL launcher — stdin closed (a confirmation reads EOF
      //   and takes its default: "no"), stderr into stdout, and a deadline:
      //   a run that hangs is killed and reported as status -1
      $Run = static function (array $arguments) use ($kit, $environment): array {
         /** @var list<string> $arguments */
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', "{$kit}/bootgly", ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $kit,
            $environment
         );
         // ? A launcher that never started would make every "nothing raw" leg true
         if (is_resource($Process) === false) {
            throw new RuntimeException('The kit launcher could not be spawned.');
         }

         /** @var array<int,resource> $pipes */
         stream_set_blocking($pipes[1], false);
         $output = '';
         $deadline = microtime(true) + 60;
         // @@ Drain until EOF or the deadline — a signal that interrupts the
         //    wait ends nothing
         while (feof($pipes[1]) === false && microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
               usleep(10000);
               continue;
            }
            $output .= (string) fread($pipes[1], 65536);
         }
         $expired = feof($pipes[1]) === false;
         fclose($pipes[1]);
         // ? Past the deadline — kill it
         if ($expired === true) {
            proc_terminate($Process, 9);
         }
         $status = proc_close($Process);

         // :
         return [$expired === true ? -1 : $status, $output];
      };
      // ! An output without its SGR — the box, the Alert and the footer colours
      $Plain = static fn (string $output): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
      // ! One box row: its label, then the whole text, then the right border —
      //   on the same line, so no LF of the text split the row
      $Quote = static fn (string $text): string => preg_quote($text, '/');
      $Row = static fn (string $plain, string $label, string $text): bool => preg_match("/│ {$Quote($label)} +{$Quote($text)} +│/u", $plain) === 1;
      // ! An excerpt for a failing description — controls escaped, and no `@`
      //   left to open markup in the report that renders it
      $Excerpt = static fn (string $output): string => str_replace('@', '(at)', (string) json_encode(
         substr($output, -900),
         JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
      ));
      // ! What in an output could drive the terminal — '' when nothing: an ESC
      //   that opens no SGR, the payload's conceal SGR (8), the SGR its markup
      //   would emit (31), a C1 code point, a BEL or a TAB
      $Find = static fn (string $output): string => preg_match('/(?:\e(?!\[[0-9;]*m)|\e\[(?:8|31)m|\xC2[\x80-\x9F]|[\x07\t]).{0,40}/s', $output, $match) === 1
         ? " — found {$Excerpt($match[0])}"
         : '';

      try {
         // ! Kit — an entry point naming its directory the working one, a
         //   registry with one project, its signature and its database config
         $root = BOOTGLY_ROOT_DIR;
         $Write("{$kit}/bootgly", <<<PHP
         <?php
         define('BOOTGLY_WORKING_BASE', __DIR__);
         define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
         (include '{$root}autoboot.php') || exit(1);

         PHP);
         $Write("{$kit}/projects/Bootgly.projects.php", <<<'PHP'
         <?php

         return [
            'Ledger' => ['interfaces' => ['CLI']],
         ];

         PHP);
         $Write("{$project}/Ledger.Project.php", <<<'PHP'
         <?php

         use Bootgly\API\Projects\Project;

         return new Project(
            name: 'Ledger',
            exportable: false,
            boot: static function (): void {}
         );

         PHP);
         // ! Every value literal (`key: ''`): no host variable redirects the history
         $Write("{$project}/configs/database/database.Config.php", <<<'PHP'
         <?php

         use Bootgly\API\Environment\Configs\Config;


         return new Config(scope: 'database')
            ->Default->bind(key: '', default: 'sqlite')
            ->Connections
               ->SQLite
                  ->Driver->bind(key: '', default: 'sqlite')
                  ->Database->bind(key: '', default: __DIR__ . '/../../database/history.sqlite')
                  ->up()
               ->up();

         PHP);
         $migration = <<<'PHP'
         <?php

         use Bootgly\ADI\Databases\SQL\Schema\Migrating;
         use Bootgly\ADI\Databases\SQL\Schema\Migration;

         return new Migration(
            Up: fn (Migrating $Schema) => null,
            Down: fn (Migrating $Schema) => null
         );

         PHP;
         $Write("{$migrations}/{$benign}.php", $migration);
         // ! A seeder that reads the history — the seed catch-all's driver message
         $Write("{$project}/database/seeders/20260601000000_audit.php", <<<'PHP'
         <?php

         use Bootgly\ADI\Databases\SQL;
         use Bootgly\ADI\Databases\SQL\Seed;
         use Bootgly\ADI\Databases\SQL\Seed\Seeder;

         return new Seeder(
            Run: static fn (SQL $Database, Seed $Seed): string => 'SELECT "migration" FROM "_bootgly_migrations"'
         );

         PHP);

         // @ The history — the DB-only row, written straight into the database
         $History = new SQLite3($database);
         $History->enableExceptions(true);
         $History->exec('CREATE TABLE "_bootgly_migrations" ("migration" VARCHAR(191) PRIMARY KEY, "batch" INTEGER, "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
         $Statement = $History->prepare('INSERT INTO "_bootgly_migrations" ("migration", "batch") VALUES (:migration, 1)');
         $Statement->bindValue(':migration', $stored, SQLITE3_TEXT);
         $Statement->execute();
         $History->close();

         // @ The control — the same name in a box row, as `status` built it before
         $Fieldset = new Fieldset(new Output('php://memory'));
         $Fieldset->content = "@#Green:Remove       @; {$stored}";
         $armed = (string) $Fieldset->render(Fieldset::RETURN_OUTPUT);

         // # Leg 1 — the history is hostile, and the name drives a box shown raw
         yield assert(
            assertion: $Read() === [$stored]
               && str_contains($armed, "\e]52;c;")
               && str_contains($armed, "\e[2J")
               && str_contains($armed, "\e[8m")
               && str_contains($armed, "\u{9D}52;c;")
               && str_contains($armed, "\u{9B}H")
               && str_contains($armed, "\e[31mFORGED")
               && preg_match('/│ Applied +999/u', $Plain($armed)) === 1,
            description: 'The history holds the name raw (read back with ext-sqlite3), and shown raw in a box row it drives the box: the OSC 52, the CSI erase, the conceal SGR and the C1 OSC and CSI arrive raw, the markup emits SGR 31, and its LF starts a forged "Applied 999" row'
         );

         // @ The detector the "nothing raw" legs rely on — each control the
         //   payloads carry, alone in a text, and the SGR the commands write
         $controls = ["\e]52;c;", "\e[2J", "\e[8m", "\e[31m", "\u{9D}52", "\u{9B}H", "\x07", "\t"];
         $flagged = 0;
         foreach ($controls as $control) {
            $flagged += $Find("text{$control}text") === '' ? 0 : 1;
         }
         $own = "\e[90m│\e[0m \e[92mApplied\e[0m \e[1m\e[37;41m FAIL \e[39;49m \e[38;5;16;48;5;11m ATTENTION \e[m\e[4mv1\e[0m";

         // # Leg 2 — the detector finds every control of the payloads, never the commands' own colours
         yield assert(
            assertion: $flagged === count($controls) && $Find($own) === '' && $Find($armed) !== '',
            description: "The detector flags each control the payloads carry ({$flagged} of 8: OSC, CSI, conceal SGR, SGR 31, C1 OSC, C1 CSI, BEL, TAB) and the box shown raw, and passes the SGR the commands write themselves"
         );

         // @ `migrate status`
         [$status, $output] = $Run(['project', 'Ledger', 'migrate', 'status']);
         $plain = $Plain($output);
         $shows = $status === 0
            && $Row($plain, 'Remove', $shown)
            && $Row($plain, 'Next', $benign)
            && preg_match('/│ Applied +999/u', $plain) === 0;
         $got = $shows === true ? '' : " — got {$Excerpt($output)}";

         // # Leg 3 — status shows the DB-only name as text, on one row
         yield assert(
            assertion: $shows,
            description: "`migrate status` (exit {$status}) shows the DB-only name in its Remove row as text — controls as \\u001b, \\u009d, \\t and \\n escapes, the markup without its introducer — on one row, beside the benign Next row, with no forged row{$got}"
         );

         $found = $Find($output);
         yield assert(
            assertion: $shows && $found === '',
            description: "`migrate status` emits nothing the name could drive: no ESC but an SGR's, no conceal SGR, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );

         // @ `migrate sync` — the check; EOF answers its confirmation
         [$status, $output] = $Run(['project', 'Ledger', 'migrate', 'sync']);
         $plain = $Plain($output);
         $names = $Read();
         $shows = $status === 0
            && $Row($plain, 'Delete first', $shown)
            && $Row($plain, 'Add first', $benign)
            && preg_match('/│ Applied +999/u', $plain) === 0
            && str_contains($plain, 'Migration sync cancelled.')
            && $names === [$stored];
         $got = $shows === true ? '' : " — got {$Excerpt($output)}";

         // # Leg 4 — sync shows the DB-only name as text and writes nothing
         yield assert(
            assertion: $shows,
            description: "`migrate sync` (exit {$status}) shows the DB-only name in its Delete first row as text, on one row, beside the benign Add first row; EOF cancels it and the history is untouched{$got}"
         );

         $found = $Find($output);
         yield assert(
            assertion: $shows && $found === '',
            description: "`migrate sync` emits nothing the name could drive: no ESC but an SGR's, no conceal SGR, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );

         // @ `migrate down 1` — the DB-only row has no file to revert with
         [$status, $output] = $Run(['project', 'Ledger', 'migrate', 'down', '1']);
         $plain = $Plain($output);
         $names = $Read();
         $shows = $status !== 0
            && str_contains($plain, ' FAIL ')
            && str_contains($plain, "Migration file not found: {$first}")
            && $names === [$stored];
         $got = $shows === true ? '' : " — got {$Excerpt($output)}";

         // # Leg 5 — the migrate catch-all shows the Runner message as text
         yield assert(
            assertion: $shows,
            description: "`migrate down 1` fails on the DB-only row (exit {$status}), its catch-all Alert shows the Runner message — the name it quotes — as text, and nothing is reverted{$got}"
         );

         $found = $Find($output);
         yield assert(
            assertion: $shows && $found === '',
            description: "The migrate catch-all emits nothing the quoted name could drive: no ESC but an SGR's, no conceal SGR, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );

         // @ A local migration whose file name carries the controls
         $Write("{$migrations}/{$local}.php", $migration);
         $present = in_array("{$local}.php", scandir($migrations) ?: [], true);
         [$status, $output] = $Run(['project', 'Ledger', 'migrate', 'status']);
         [$synced, $checked] = $Run(['project', 'Ledger', 'migrate', 'sync']);
         $plain = $Plain($output);
         $listing = $Plain($checked);
         $shows = $present
            && $status === 0
            && $synced === 0
            && $Row($plain, 'Next', $listed)
            && $Row($listing, 'Add first', $listed)
            && preg_match('/│ DB only +0/u', "{$plain}{$listing}") === 0;
         $got = $shows === true ? '' : " — got {$Excerpt($output)} and {$Excerpt($checked)}";

         // # Leg 6 — a local file name shows as text in the Next and Add first rows
         yield assert(
            assertion: $shows,
            description: "A local migration file named with the controls is on disk and shows as text in the Next row of `migrate status` (exit {$status}) and the Add first row of `migrate sync` (exit {$synced}), each on one row{$got}"
         );

         $found = "{$Find($output)}{$Find($checked)}";
         yield assert(
            assertion: $shows && $found === '',
            description: "Neither run emits anything the file name could drive: no ESC but an SGR's, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );

         // @ A history the driver cannot read — `_bootgly_migrations` is a view
         //   over a table, then dropped, whose name carries the payload
         unlink($database);
         $History = new SQLite3($database);
         $History->enableExceptions(true);
         $History->exec("CREATE TABLE \"{$dropped}\" (\"migration\" TEXT, \"batch\" INTEGER, \"created_at\" TEXT)");
         $History->exec("CREATE VIEW \"_bootgly_migrations\" AS SELECT \"migration\", \"batch\", \"created_at\" FROM \"{$dropped}\"");
         $History->exec("DROP TABLE \"{$dropped}\"");
         $error = '';
         try {
            $History->query('SELECT "migration" FROM "_bootgly_migrations"');
         }
         catch (SQLite3Exception $Exception) {
            $error = $Exception->getMessage();
         }
         $History->close();

         // # Leg 7 — the driver's own error quotes the dropped name raw
         yield assert(
            assertion: str_contains($error, $dropped),
            description: 'The history view cannot be read, and the SQLite error (read with ext-sqlite3) quotes the dropped table name raw — its controls and markup included'
         );

         // @ `migrate status` over the broken history
         [$status, $output] = $Run(['project', 'Ledger', 'migrate', 'status']);
         $plain = $Plain($output);
         $shows = $status !== 0
            && str_contains($plain, ' FAIL ')
            && str_contains($plain, $reported);
         $got = $shows === true ? '' : " — got {$Excerpt($output)}";

         // # Leg 8 — the migrate catch-all shows the driver message as text
         yield assert(
            assertion: $shows,
            description: "`migrate status` over a history the driver cannot read fails (exit {$status}) and its catch-all Alert shows the driver message — the table name it quotes — as text{$got}"
         );

         $found = $Find($output);
         yield assert(
            assertion: $shows && $found === '',
            description: "The migrate catch-all emits nothing the driver message could drive: no ESC but an SGR's, no conceal SGR, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );

         // @ `seed run` — its seeder reads the same broken history
         [$status, $output] = $Run(['project', 'Ledger', 'seed', 'run']);
         $plain = $Plain($output);
         $shows = $status !== 0
            && str_contains($plain, ' FAIL ')
            && str_contains($plain, $reported);
         $got = $shows === true ? '' : " — got {$Excerpt($output)}";

         // # Leg 9 — the seed catch-all shows the driver message as text
         yield assert(
            assertion: $shows,
            description: "`seed run` over a history the driver cannot read fails (exit {$status}) and its catch-all Alert shows the driver message as text{$got}"
         );

         $found = $Find($output);
         yield assert(
            assertion: $shows && $found === '',
            description: "The seed catch-all emits nothing the driver message could drive: no ESC but an SGR's, no conceal SGR, no SGR 31, no C1 code point, no BEL, no TAB{$found}"
         );
      }
      finally {
         $Erase($directory);
      }
   }
);

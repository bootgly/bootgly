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


use const ARRAY_FILTER_USE_KEY;
use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_TTY;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function count;
use function crc32;
use function explode;
use function fclose;
use function feof;
use function fread;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function microtime;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function strrpos;
use function strtotime;
use function substr;
use function usleep;
use Generator;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Backlog;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Filters;
use Bootgly\ACI\Logs\Filters\Callback;
use Bootgly\ACI\Logs\Filters\Channel;
use Bootgly\ACI\Logs\Filters\Level;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\States;
use Bootgly\API\Projects;
use Bootgly\CLI\Command;
use Bootgly\CLI\UX\Components\Tail;


class LogsCommand extends Command
{
   // * Config
   public int $group = 1;
   /** Directory holding the persisted JSON-line logs (the shared kit storage by default). */
   public string $directory = BOOTGLY_STORAGE_DIR . 'logs/';

   // * Data
   public string $name = 'logs';
   public string $description = 'View and follow Bootgly logs (backlog + live)';

   // * Metadata
   /** @var array<int,true> Bounded de-dup window across the live and file lanes. */
   private array $seen = [];

   /** @var array<string,array<string>> */
   public array $options = [
      // Global options
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      // Local options
      'Keep following new records (unrelated to `project start -f`)' => ['-f', '--follow'],
      'Only one project\'s records' => ['--project=<Name>'],
      'Only framework records' => ['--framework'],
      'Only one instance\'s records — port (servers) or master PID (console); with -f also its live tap' => ['--instance=<id>'],
      'Only one channel (comma-separates)' => ['--channel=<channel>'],
      'Minimum severity (debug..emergency)' => ['--level=<level>'],
      'Start point (strtotime, or 30s/15m/2h/7d)' => ['--since=<time>'],
      'Machine output — one JSON record per line' => ['--json'],
   ];


   public function run (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Options
      $follow = isSet($options['f']) || isSet($options['follow']);
      $json = isSet($options['json']);

      // ! Fresh de-dup window per run (the registered command is a singleton)
      $this->seen = [];

      $since = $this->cut($options['since'] ?? null);
      if ($since === null) {
         $Output->render('@#red:Invalid --since value.@; Use strtotime syntax or 30s/15m/2h/7d.@.;');
         return false;
      }

      $Filters = $this->sieve($options);
      if ($Filters === null) {
         return false;
      }

      // ! Sources
      $Backlog = new Backlog($this->directory);

      // @ Live lane FIRST when following: an ambiguous target must refuse before
      //   a single backlog byte prints (the `project restart` discipline)
      $Taps = [];
      if ($follow === true) {
         [$Taps, $notes, $refused] = $this->attach($options);
         if ($refused === true) {
            return false;
         }
         if ($json === false) {
            foreach ($notes as $note) {
               $Output->render($note);
            }
         }
      }

      // @ When following, the sources are built AND primed before the backlog read:
      //   the file lane captures its offsets now, so records appended DURING the
      //   backlog merge flow into the live view instead of falling between lanes
      $Source = null;
      if ($follow === true) {
         $Source = $this->source($Backlog, $Filters, $Taps);
         $Source->current();
      }

      // @ Backlog: everything already persisted, filtered and time-bounded
      $printed = 0;
      $seedLines = [];
      $Formatter = new JSON;
      foreach ($Backlog->read($since) as $Record) {
         if ($Filters->check($Record) === false) {
            continue;
         }
         if ($follow === true) {
            // ? Register the record in the de-dup window: its live tap copy
            //   (buffered since attach) must not print a second time
            $this->note($Formatter->format($Record));
         }
         if ($follow === true && $json === false && BOOTGLY_TTY === true) {
            // ? TTY follow: records pre-seed the viewer instead of printing —
            //   bounded to the viewer's own ring size
            $seedLines[] = $Formatter->format($Record);
            if (count($seedLines) > 6000) {
               $seedLines = array_slice($seedLines, -5000);
            }
            continue;
         }
         $this->print($Record, $json);
         $printed++;
      }

      // ?: Without -f (no follow source built), the backlog is the whole answer
      if ($Source === null) {
         if ($printed === 0 && $json === false) {
            $Output->render('@#black:No log records matched.@;@.;');
         }
         return true;
      }

      if ($json === false && BOOTGLY_TTY === true) {
         // : Same screen, filters and keys as the server Monitor
         $Tail = new Tail(CLI->Terminal->Input, $Output);
         if ($seedLines !== []) {
            $Tail->Viewer->feed(implode('', array_slice($seedLines, -5000)));
         }
         $Tail->run($Source);
         return true;
      }

      // @@ Stream lane (no TTY or --json): composable output, Ctrl+C ends it —
      //   one format per stream (JSON stays raw; otherwise every record renders
      //   as a full terminal Line, backlog and live alike)
      foreach ($Source as $chunk) {
         if ($chunk === '') {
            continue;
         }
         if ($json === true) {
            $Output->write($chunk);
            continue;
         }
         foreach (explode("\n", $chunk) as $line) {
            if ($line === '') {
               continue;
            }
            $data = json_decode($line, true);
            if (is_array($data) === true) {
               /** @var array<string,mixed> $data JSON objects decode with string keys */
               $this->print(Record::import($data), false);
            }
         }
      }

      // :
      return true;
   }

   /**
    * Attach to the live tap socket of every targeted, authenticated server instance.
    *
    * Console instances have no socket — their records arrive through the file lane.
    * With `--project` and several live instances, an omitted `--instance` lists the
    * qualifiers and refuses (the `project restart` discipline). The kit scope attaches
    * to every project's live taps. Here `--instance` only selects which live tap to
    * attach — the same option also filters both lanes by the record's `instance`
    * field (sieve()), so a targeted instance never bleeds through the file lane.
    *
    * @param array<string,mixed> $options
    * @return array{0:array<int,resource>,1:array<int,string>,2:bool} [sockets, notes, refused]
    */
   private function attach (array $options): array
   {
      $Output = CLI->Terminal->Output;
      $Sockets = [];
      $notes = [];

      $project = $options['project'] ?? null;
      $scoped = is_string($project) && $project !== '';
      $instance = $options['instance'] ?? null;
      $instance = is_string($instance) && $instance !== '' ? $instance : null;

      // ! Candidate projects: one (project scope) or every registered one (kit scope)
      $paths = $scoped ? [$project] : array_keys(Projects::read());
      $matched = false;

      foreach ($paths as $path) {
         $id = Projects::encode((string) $path);
         $instances = States::scan($id);

         // ? The instance qualifier is a tiebreaker, never an address
         if ($instance !== null) {
            $instances = array_filter(
               $instances,
               static fn ($qualifier) => (string) $qualifier === $instance,
               ARRAY_FILTER_USE_KEY
            );
            $matched = $matched || $instances !== [];
            // ? Nothing live answers to that qualifier — say so instead of a silent
            //   follow (the record filter still narrows the files to that instance)
            if ($scoped === true && $instances === []) {
               $notes[] = "@#black:No instance $instance registered for@; @#cyan:$path@;@#black:"
                  . " — reading its files only.@;@.;";
            }
         }
         else if ($scoped === true && count($instances) > 1) {
            // ?: Several live instances and no tiebreaker — list and refuse
            $qualifiers = implode(', ', array_map('strval', array_keys($instances)));
            $Output->render(
               "@#red:Project@; @#cyan:$path@; @#red:has multiple running instances@; ($qualifiers)."
               . " Use @#cyan:--instance=<id>@; to follow one.@.;"
            );
            return [[], [], true];
         }

         foreach ($instances as $qualifier => $data) {
            // ? Console instances stream through their sink files instead
            if ($data['type'] !== 'WPI') {
               continue;
            }

            // ! The tap pathname is RECOMPUTED from the instance identity — the state
            //   JSON's `tap` field is advisory, never a trusted connect target
            try {
               $State = new State($id, (string) $qualifier !== '' ? (string) $qualifier : null);
            }
            catch (Throwable) {
               continue;
            }

            $Socket = @stream_socket_client("unix://{$State->tapFile}", $code, $error, 1.0);
            if ($Socket === false) {
               $notes[] = "@#black:No live tap for@; @#cyan:$path@;@#black:"
                  . ((string) $qualifier !== '' ? " (instance $qualifier)" : '')
                  . " — following files only.@;@.;";
               continue;
            }
            stream_set_blocking($Socket, false);
            $Sockets[] = $Socket;
         }
      }

      // ? Kit scope: no project answers to that qualifier — the same note, once
      if ($instance !== null && $scoped === false && $matched === false) {
         $notes[] = "@#black:No instance $instance registered — reading files only.@;@.;";
      }

      // :
      return [$Sockets, $notes, false];
   }

   /**
    * Resolve the `--since` option into a UNIX timestamp cutoff.
    *
    * @param mixed $since Option value (`30s/15m/2h/7d`, strtotime syntax, or absent).
    * @return null|float 0.0 when absent; null when unparseable.
    */
   private function cut (mixed $since): null|float
   {
      // ? Absent → no cutoff; a bare `--since` (no value) is refused, not ignored
      if ($since === null) {
         return 0.0;
      }
      if (is_string($since) === false || $since === '') {
         return null;
      }

      // ? Duration suffix (relative to now)
      if (preg_match('/^(\d+)([smhd])$/', $since, $matches) === 1) {
         $units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
         return microtime(true) - ((int) $matches[1] * $units[$matches[2]]);
      }

      // ? strtotime syntax (absolute or relative)
      $timestamp = strtotime($since);

      // :
      return $timestamp === false ? null : (float) $timestamp;
   }

   /**
    * Build the record filter chain from the command options.
    *
    * @param array<string,mixed> $options
    * @return null|Filters null when an option value is invalid (already reported).
    */
   private function sieve (array $options): null|Filters
   {
      $Output = CLI->Terminal->Output;
      $Filters = new Filters;

      // # Severity floor
      $level = $options['level'] ?? null;
      if (is_string($level) === true && $level !== '') {
         $Min = Levels::fetch($level);
         if ($Min === null) {
            $Output->render("@#red:Unknown --level:@; $level@.;");
            return null;
         }
         $Filters->push(new Level(Min: $Min));
      }

      // # Channels
      $channel = $options['channel'] ?? null;
      if (is_string($channel) === true && $channel !== '') {
         $Filters->push(new Channel(allowed: explode(',', $channel)));
      }

      // # Provenance
      $project = $options['project'] ?? null;
      $framework = isSet($options['framework']);
      if ($framework === true && is_string($project) === true) {
         $Output->render('@#red:--project and --framework are mutually exclusive.@;@.;');
         return null;
      }
      if ($framework === true) {
         $Filters->push(new Callback(static function (Record $Record): bool {
            return $Record->project === 'framework';
         }));
      }
      else if (is_string($project) === true && $project !== '') {
         $Filters->push(new Callback(static function (Record $Record) use ($project): bool {
            return $Record->project === $project;
         }));
      }

      // # Instance — the record's own stamp (port or master PID), exact string: a line
      //   written before the field existed carries '' and never matches
      $instance = $options['instance'] ?? null;
      if ($instance !== null && (is_string($instance) === false || $instance === '')) {
         // ? A bare `--instance` is refused, not ignored (the `--since` discipline)
         $Output->render('@#red:Invalid --instance value.@; Pass the qualifier: --instance=<port|PID>.@.;');
         return null;
      }
      if (is_string($instance) === true) {
         $Filters->push(new Callback(static function (Record $Record) use ($instance): bool {
            return $Record->instance === $instance;
         }));
      }

      // :
      return $Filters;
   }

   /**
    * The follow source: filtered NDJSON chunks from the live taps and the file lane,
    * merged and de-duplicated ('' when idle — the caller paces when no taps select).
    *
    * @param array<int,resource> $Taps Attached tap sockets (empty = file lane only).
    * @return Generator<int,string>
    */
   private function source (Backlog $Backlog, Filters $Filters, array $Taps = []): Generator
   {
      /** @var array<int,string> $partials Per-socket partial NDJSON line carry */
      $partials = [];

      foreach ($Backlog->following() as $chunk) {
         $passed = '';

         // @ Live lane first: records reach the screen before their sink write lands
         if ($Taps !== []) {
            $read = $Taps;
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
               foreach ($read as $Socket) {
                  $id = (int) $Socket;
                  $bytes = @fread($Socket, Backlog::MAX_LINE_BYTES);
                  if (($bytes === '' || $bytes === false) && feof($Socket) === true) {
                     // ? The instance went away — its files keep following
                     fclose($Socket);
                     unset($Taps[array_search($Socket, $Taps, true)], $partials[$id]);
                     continue;
                  }
                  $buffer = ($partials[$id] ?? '') . (string) $bytes;
                  $cut = strrpos($buffer, "\n");
                  if ($cut === false) {
                     // ? Bound the carry: a line above the frame cap can never
                     //   complete legitimately — discard it instead of growing
                     $partials[$id] = strlen($buffer) > Backlog::MAX_LINE_BYTES ? '' : $buffer;
                     continue;
                  }
                  $partials[$id] = substr($buffer, $cut + 1);
                  $passed .= $this->refine(substr($buffer, 0, $cut + 1), $Filters);
               }
            }
         }

         // ? No sockets to select on (none attached, or every instance gone):
         //   pace the file polling here — the caller never has to
         if ($Taps === []) {
            usleep(100000);
         }

         // @ File lane: whatever the sinks persisted since the last cycle
         if ($chunk !== '') {
            $passed .= $this->refine($chunk, $Filters);
         }

         yield $passed;
      }
   }

   /**
    * Filter an NDJSON chunk, re-emitting only the raw lines whose records pass —
    * each line once: a record arriving both live (tap) and persisted (sink file)
    * is de-duplicated by its exact bytes.
    */
   private function refine (string $chunk, Filters $Filters): string
   {
      $passed = '';
      foreach (explode("\n", $chunk) as $line) {
         if ($line === '') {
            continue;
         }

         // ? De-dup across the live and file lanes (bounded window)
         if ($this->note($line) === false) {
            continue;
         }

         $data = json_decode($line, true);
         if (is_array($data) === false) {
            continue;
         }
         /** @var array<string,mixed> $data JSON objects decode with string keys */
         if ($Filters->check(Record::import($data)) === false) {
            continue;
         }
         $passed .= str_ends_with($line, "\n") === true ? $line : "$line\n";
      }

      // :
      return $passed;
   }

   /**
    * Register one raw JSON line in the bounded de-dup window.
    *
    * A record can arrive twice — live (tap frame) and persisted (sink file) — with
    * identical bytes; only the first registration passes. Above ~1000 records/s the
    * 512-line window can evict a key before its twin arrives (duplicates reappear),
    * and a crc32 collision inside the window suppresses a distinct record (~1 in
    * 8.4M lines) — accepted trade-offs for a live viewer.
    *
    * @return bool True when the line is fresh (first sighting).
    */
   private function note (string $line): bool
   {
      $key = crc32(rtrim($line, "\n"));
      if (isSet($this->seen[$key]) === true) {
         return false;
      }
      $this->seen[$key] = true;
      if (count($this->seen) > 512) {
         unset($this->seen[array_key_first($this->seen)]);
      }

      // :
      return true;
   }

   /**
    * Print one record — raw JSON line, or a full terminal line.
    */
   private function print (Record $Record, bool $json): void
   {
      $Output = CLI->Terminal->Output;

      if ($json === true) {
         $Output->write((new JSON)->format($Record));
         return;
      }

      // ? Line output shows every segment regardless of the process display mask
      $saved = Display::$segments;
      Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);
      $Output->write((new Line)->format($Record));
      Display::show($saved);
   }
}

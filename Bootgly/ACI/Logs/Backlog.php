<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs;


use const SEEK_END;
use function array_keys;
use function clearstatcache;
use function fclose;
use function feof;
use function fgets;
use function file_exists;
use function fileinode;
use function filemtime;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function glob;
use function is_array;
use function json_decode;
use function rtrim;
use function str_ends_with;
use function strlen;
use function strrpos;
use function substr;
use function usort;
use Generator;
use SplPriorityQueue;

use Bootgly\ACI\Logs\Data\Record;


/**
 * Reads the log records a directory of JSON-line sinks has persisted — the backlog —
 * and follows the same files for records appended after the read started.
 */
class Backlog
{
   // Parity with the live tap frame cap (PipeHandler::MAX_FRAME_BYTES).
   public const int MAX_LINE_BYTES = 65536;

   // * Config
   public string $directory;
   public bool $rotations;


   /**
    * @param string $directory Directory holding `*.log` JSON-line files (e.g. `storage/logs/`).
    * @param bool $rotations Whether rotated archives (`*.log.N`) join the backlog.
    */
   public function __construct (string $directory, bool $rotations = true)
   {
      // * Config
      $this->directory = rtrim($directory, '/') . '/';
      $this->rotations = $rotations;
   }

   /**
    * List the log files in the directory — rotated archives (oldest first) before their
    * active file, so per-file order follows append order.
    *
    * @param float $since Skip rotated archives last modified before this UNIX timestamp (0 = all).
    * @return array<int,string> Absolute file paths.
    */
   public function scan (float $since = 0.0): array
   {
      // ! Active files + rotations, grouped per active file
      $actives = glob("{$this->directory}*.log") ?: [];
      $files = [];
      foreach ($actives as $active) {
         if ($this->rotations === true) {
            $archives = glob("$active.[0-9]*") ?: [];
            // @ Highest numeric suffix = oldest content (x.log.3 before x.log.1)
            usort($archives, static function (string $a, string $b): int {
               $an = (int) substr($a, (int) strrpos($a, '.') + 1);
               $bn = (int) substr($b, (int) strrpos($b, '.') + 1);
               return $bn <=> $an;
            });
            foreach ($archives as $archive) {
               // ? Bound --since reads: a rotation finished before the cutoff holds nothing newer
               if ($since > 0.0 && (float) filemtime($archive) < $since) {
                  continue;
               }
               $files[] = $archive;
            }
         }
         $files[] = $active;
      }

      // :
      return $files;
   }

   /**
    * Read every persisted record, merged ascending by timestamp across files.
    *
    * Malformed lines and lines above MAX_LINE_BYTES are skipped. Each line is rebuilt
    * through Record::import(), so provenance defaults to `framework` for legacy lines.
    *
    * @param float $since Only records stamped at or after this UNIX timestamp (0 = all).
    * @return Generator<int,Record>
    */
   public function read (float $since = 0.0): Generator
   {
      // ! One reader per file; a k-way merge keyed by record timestamp
      $Readers = [];
      foreach ($this->scan($since) as $index => $file) {
         $Handle = @fopen($file, 'rb');
         if ($Handle === false) {
            continue;
         }
         $Readers[$index] = $Handle;
      }

      /** @var SplPriorityQueue<float,array{0:Record,1:int}> $Queue */
      $Queue = new SplPriorityQueue;
      $advance = function (int $index) use (&$Readers, $Queue, $since): void {
         $Handle = $Readers[$index] ?? null;
         if ($Handle === null) {
            return;
         }
         // @@ Pull the next valid record of this file into the queue
         while (($line = fgets($Handle, self::MAX_LINE_BYTES + 2)) !== false) {
            // ? Oversized line (no newline within the cap): discard through its end
            if (strlen($line) > self::MAX_LINE_BYTES && str_ends_with($line, "\n") === false) {
               while (($rest = fgets($Handle, self::MAX_LINE_BYTES + 2)) !== false) {
                  if (str_ends_with($rest, "\n") === true) {
                     break;
                  }
               }
               continue;
            }

            $data = json_decode($line, true);
            if (is_array($data) === false) {
               continue;
            }

            /** @var array<string,mixed> $data JSON objects decode with string keys */
            $Record = Record::import($data);
            if ($since > 0.0 && $Record->timestamp < $since) {
               continue;
            }

            // @ SplPriorityQueue is a max-heap: negate for ascending time
            $Queue->insert([$Record, $index], -$Record->timestamp);
            return;
         }

         // ? Exhausted
         fclose($Handle);
         unset($Readers[$index]);
      };

      foreach (array_keys($Readers) as $index) {
         $advance($index);
      }

      // @@ Drain ascending, refilling from the emitted record's file
      while ($Queue->isEmpty() === false) {
         /** @var array{0:Record,1:int} $entry */
         $entry = $Queue->extract();
         yield $entry[0];
         $advance($entry[1]);
      }
   }

   /**
    * Follow the directory for content appended after the call — the file-based live lane.
    *
    * Yields raw NDJSON chunks ('' when idle — the caller paces the loop); re-globs so new
    * channel files are picked up, and reopens a file whose inode changed or size shrank
    * (rotation/truncation).
    *
    * @return Generator<int,string>
    */
   public function following (): Generator
   {
      // ! Start at each current end — only NEW content flows
      /** @var array<string,array{0:resource,1:int}> $Handles path => [handle, inode] */
      $Handles = [];
      $open = static function (string $file, bool $end) use (&$Handles): void {
         $Handle = @fopen($file, 'rb');
         if ($Handle === false) {
            return;
         }
         $stat = fstat($Handle);
         if ($end === true) {
            fseek($Handle, 0, SEEK_END);
         }
         $Handles[$file] = [$Handle, (int) ($stat['ino'] ?? 0)];
      };

      foreach (glob("{$this->directory}*.log") ?: [] as $file) {
         $open($file, true);
      }

      // @@ Poll cycle: rotation/new-file detection + appended bytes — the follow
      //   lane lives as long as its consumer keeps iterating
      while (true) { // @phpstan-ignore-line
         clearstatcache();

         // ? New channel files appear mid-follow; rotated files change inode;
         //   copy+truncate keeps the inode but shrinks below our offset
         $chunk = '';
         foreach (glob("{$this->directory}*.log") ?: [] as $file) {
            if (isSet($Handles[$file]) === false) {
               $open($file, false);
               continue;
            }
            $inode = (int) @fileinode($file);
            if ($inode !== $Handles[$file][1] && $inode !== 0) {
               // @ Rename rotation: drain the old inode's unread tail before
               //   switching, so no record is lost at the rotation boundary
               while (($tail = fread($Handles[$file][0], self::MAX_LINE_BYTES)) !== false && $tail !== '') {
                  $chunk .= $tail;
               }
               fclose($Handles[$file][0]);
               unset($Handles[$file]);
               $open($file, false);
            }
            else if ((int) @filesize($file) < (int) ftell($Handles[$file][0])) {
               // @ Truncation (same inode, size below our offset): restart at zero
               fseek($Handles[$file][0], 0);
            }
         }

         // @ Read appended bytes from every followed file
         foreach ($Handles as $file => [$Handle, $inode]) {
            if (file_exists($file) === false) {
               fclose($Handle);
               unset($Handles[$file]);
               continue;
            }
            // @@ fread directly: feof() latches true at EOF and would mask later appends
            while (true) {
               $bytes = fread($Handle, self::MAX_LINE_BYTES);
               if ($bytes === false || $bytes === '') {
                  break;
               }
               $chunk .= $bytes;
            }
         }

         yield $chunk;
      }
   }
}

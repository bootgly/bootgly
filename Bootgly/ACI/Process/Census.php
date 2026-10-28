<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use function array_flip;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function fclose;
use function function_exists;
use function implode;
use function is_executable;
use function is_file;
use function is_resource;
use function is_string;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use DateTimeImmutable;
use DateTimeZone;


/**
 * The kernel process table, read through the system `ps`: each process's
 * parent, user IDs, scheduler state and birth. It is the portable view where
 * the kernel has no procfs (Darwin), and the same keywords print the same
 * columns from procps (Linux) and BSD `ps`.
 */
class Census
{
   // * Config
   /**
    * The system `ps`, by absolute path only — never a PATH lookup: a root
    * operator's `stop` must not run a planted one.
    */
   public const array BINARIES = ['/bin/ps', '/usr/bin/ps'];
   /**
    * The most distinct PIDs one read accepts: a bound on the `-p` list one
    * `ps` run is given, far below any argument-size limit.
    */
   public const int LIMIT = 4097;


   /**
    * Read the kernel process-table rows of live PIDs — one `ps` run, no
    * shell: a fixed argv, a clean environment, the C locale and UTC.
    *
    * `birth` is the process start time in epoch seconds: exact on Darwin,
    * derived from the boot time and the start ticks by procps (Linux), so
    * it may differ from the real start by a second there.
    *
    * @param int ...$PIDs The processes to read: each above 0 (never a process
    *                     group), at most `LIMIT` distinct.
    *
    * @return array<int,array{ppid:int,uid:int,ruid:int,svuid:int,state:string,birth:int}>
    *         Rows by PID. An absent PID (gone, reaped) has no row; a refused
    *         list, or a host without `ps`, has no rows at all.
    */
   public static function read (int ...$PIDs): array
   {
      // ?
      $PIDs = array_values(array_unique($PIDs));
      if ($PIDs === [] || count($PIDs) > self::LIMIT) {
         return [];
      }
      foreach ($PIDs as $PID) {
         if ($PID <= 0) {
            return [];
         }
      }
      if (function_exists('proc_open') === false) {
         return [];
      }

      // !
      $binary = null;
      foreach (self::BINARIES as $candidate) {
         if (is_file($candidate) && is_executable($candidate)) {
            $binary = $candidate;
            break;
         }
      }
      if ($binary === null) {
         return [];
      }

      // @ One `-o` per keyword: POSIX takes the rest of an `-o` argument
      //   after `=` as the column header, so a BSD `ps` reads `pid=,ppid=`
      //   as ONE column titled `,ppid=`. Empty headers print no header line.
      $pipes = [];
      $Process = @proc_open(
         [
            $binary,
            '-o', 'pid=',
            '-o', 'ppid=',
            '-o', 'uid=',
            '-o', 'ruid=',
            '-o', 'svuid=',
            '-o', 'stat=',
            '-o', 'lstart=',
            '-p', implode(',', $PIDs)
         ],
         [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w']
         ],
         $pipes,
         '/',
         ['LC_ALL' => 'C', 'TZ' => 'UTC', 'PATH' => '/usr/bin:/bin']
      );
      if (is_resource($Process) === false) {
         return [];
      }

      $output = @stream_get_contents($pipes[1], 1048576);
      fclose($pipes[1]);
      // ! The exit status is no verdict: procps and BSD `ps` both exit 1
      //   when a requested PID is absent
      proc_close($Process);

      if (is_string($output) === false) {
         return [];
      }

      // !
      $requested = array_flip($PIDs);
      $UTC = new DateTimeZone('UTC');
      $rows = [];

      // @@ `lstart` prints `%a %b %e %H:%M:%S %Y` in the C locale on both
      //    (`%e` pads a one-digit day with a space)
      foreach (explode("\n", $output) as $line) {
         if (
            preg_match(
               '/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+([A-Z][a-z]{2} [A-Z][a-z]{2} [ \d]\d \d{2}:\d{2}:\d{2} \d{4})\s*$/D',
               $line,
               $match
            ) !== 1
         ) {
            continue;
         }

         $PID = (int) $match[1];
         if (isSet($requested[$PID]) === false) {
            continue;
         }

         $Birth = DateTimeImmutable::createFromFormat(
            '!D M j H:i:s Y',
            (string) preg_replace('/ +/', ' ', $match[7]),
            $UTC
         );
         if ($Birth === false) {
            continue;
         }

         $rows[$PID] = [
            'ppid'  => (int) $match[2],
            'uid'   => (int) $match[3],
            'ruid'  => (int) $match[4],
            'svuid' => (int) $match[5],
            'state' => $match[6],
            'birth' => $Birth->getTimestamp()
         ];
      }

      // :
      return $rows;
   }
}

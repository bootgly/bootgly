<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers;


use const BOOTGLY_ENVIRONMENT;
use const LOCK_EX;
use const LOCK_UN;
use const LOG_PID;
use const LOG_USER;
use const LOG_WARNING;
use const PREG_SET_ORDER;
use const SEEK_END;
use function basename;
use function bin2hex;
use function defined;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function flock;
use function fopen;
use function fseek;
use function fstat;
use function function_exists;
use function fwrite;
use function getcwd;
use function is_dir;
use function is_link;
use function link;
use function lstat;
use function mkdir;
use function openlog;
use function posix_getuid;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function random_bytes;
use function readlink;
use function realpath;
use function str_contains;
use function str_replace;
use function strlen;
use function syslog;
use function trim;
use function unlink;
use Throwable;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers\File\Rotation;


class File extends Handler
{
   // * Config
   public string $path;
   public Rotation $Rotation;

   // * Data
   /** Whether refusals stay out of the system logger — see mute(). */
   private static bool $quiet = false;
   /** The runtime identity a privileged writer guards against — see guard(). */
   private static null|int $guarded = null;

   // * Metadata
   /** @var array<string,true> Refusals already reported — once per path and reason, per process. */
   private static array $refused = [];
   /** Whether ext-posix is loaded — a privileged writer is only known where it is. */
   private static null|bool $posix = null;
   /** The uid of an owner outside this user namespace, -1 outside one — see trust(). */
   private static null|int $overflow = null;


   /**
    * Write records to a file, rotating it per the rotation policy.
    *
    * @param string $path Destination log file path. A `{channel}` placeholder is replaced per record
    *                     with the record's channel, writing one file per module (e.g. `logs/{channel}.log`);
    *                     a `{project}` placeholder is replaced with the record's provenance, separating
    *                     framework and project records (e.g. `logs/{project}/{channel}.log`).
    * @param null|Formatter $Formatter Output formatter (defaults to JSON — structured, ANSI-free lines).
    * @param Levels $Level Minimum severity this handler accepts.
    * @param null|Rotation $Rotation Rotation policy (defaults to daily + 10MB, keep 7).
    */
   public function __construct (
      string $path,
      null|Formatter $Formatter = null,
      Levels $Level = Levels::Debug,
      null|Rotation $Rotation = null
   )
   {
      // ? Files default to JSON: no ANSI, and independent of the terminal Display segments
      parent::__construct($Formatter ?? new JSON, $Level);

      // * Config
      $this->path = $path;
      $this->Rotation = $Rotation ?? new Rotation;
   }

   /**
    * Ensure the directory, rotate if due, then append the formatted record.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (supplies the `{channel}` and `{project}` placeholders).
    * @return bool True on success.
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // ? Resolve the {channel} placeholder per record (sanitized — no path traversal)
      $path = $this->path;
      if (str_contains($path, '{channel}') === true) {
         $channel = preg_replace('/[^A-Za-z0-9._-]/', '_', $Record->channel) ?? '';
         $path = str_replace('{channel}', $channel !== '' ? $channel : 'default', $path);
      }
      // ? Resolve the {project} placeholder per record (sanitized — no path traversal;
      //   the dot-only names the character class would keep are refused explicitly)
      if (str_contains($path, '{project}') === true) {
         $project = preg_replace('/[^A-Za-z0-9._-]/', '_', $Record->project) ?? '';
         if ($project === '' || trim($project, '.') === '') {
            $project = 'default';
         }
         $path = str_replace('{project}', $project, $path);
      }

      // ? A privileged writer trusts no path another identity can steer — checked
      //   BEFORE anything touches the pathname: the directory it would create,
      //   the rotation, the file
      $directory = dirname($path);
      if (@is_dir($directory) === false) {
         if ($this->trust($path, $directory) === false) {
            return false;
         }
         // ! A privileged writer creates a directory its own walk accepts —
         //   root's and writable by nobody else — whatever the umask says
         $privileged = (self::$posix ??= function_exists('posix_getuid')) && posix_getuid() === 0;
         @mkdir($directory, $privileged ? 0o755 : 0o775, true);
      }
      if ($this->trust($path, $directory) === false) {
         return false;
      }

      // @ Rotate when due
      $this->Rotation->rotate($path);

      // @ Open — deciding on the inode opened, never on the pathname alone
      $handle = $this->open($path);
      if ($handle === false) {
         return false;
      }

      // @ Append under the lock: the handle carries no O_APPEND (see open()),
      //   so the end is sought here, once the lock serialises the writers.
      //   Nothing here may raise: a sink that cannot write must never take
      //   the process that logs down with it.
      $written = @flock($handle, LOCK_EX)
         && @fseek($handle, 0, SEEK_END) === 0
         && @fwrite($handle, $formatted) === strlen($formatted);
      @flock($handle, LOCK_UN);
      @fclose($handle);

      // ?: A write that fell short is reported like a refusal — once per path
      if ($written === false) {
         return $this->refuse($path, 'the write failed — disk full or read-only?');
      }

      // :
      return true;
   }

   /**
    * Decide whether a privileged writer may write under this directory at all.
    *
    * Root follows no path another identity can steer: every directory on the
    * way up to the filesystem root must belong to root and be writable by
    * nobody else — or be a sticky directory, where nobody can replace what is
    * not theirs — and a link on the way must belong to root, who then chose
    * its target — or a sticky directory, where only its owner may replace what
    * is not theirs, unless that owner is the identity guarded against (see
    * guard()). Inside a user namespace an owner with no identity here (the
    * overflow uid) cannot act from within it and counts as root's — a volume
    * owned by an unmapped host identity is therefore accepted, and that
    * identity can still read or rotate what lands there from outside; the
    * inode checks on open() keep it from steering a write. An unprivileged
    * writer is trusted with whatever it can reach.
    *
    * @param string $path The destination pathname (named in a refusal).
    * @param string $directory Its directory.
    * @return bool
    */
   private function trust (string $path, string $directory): bool
   {
      $privileged = (self::$posix ??= function_exists('posix_getuid')) && posix_getuid() === 0;
      if ($privileged === false) {
         return true;
      }

      // @ Walk the path up: no component another identity could steer. A
      //   directory still to be created is walked from its closest existing
      //   ancestor, which decides whether root creates it. Inside a user
      //   namespace an owner with no identity here (the overflow uid) cannot
      //   act, and counts as root's.
      if (self::$overflow === null) {
         self::$overflow = self::exempt(
            (string) @file_get_contents('/proc/self/uid_map'),
            (int) @file_get_contents('/proc/sys/fs/overflowuid')
         );
      }
      $overflow = self::$overflow;
      $current = $directory[0] === '/' ? $directory : ((string) getcwd()) . "/$directory";
      while ($current !== '' && $current !== '/' && $current !== '.' && @file_exists($current) === false && @is_link($current) === false) {
         $current = dirname($current);
      }
      $walked = 0;
      while ($current !== '' && $current !== '/' && $current !== '.') {
         if (++$walked > 64) {
            return $this->refuse($path, 'the directory path is too deep or loops');
         }
         $inode = @lstat($current);
         if ($inode === false) {
            return $this->refuse($path, 'a component of the directory path is missing');
         }
         $mode = (int) $inode['mode'];
         $foreign = (int) $inode['uid'] !== 0 && (int) $inode['uid'] !== $overflow;
         // # A link: root's, and followed once
         if (($mode & 0170000) === 0120000) {
            if ($foreign) {
               return $this->refuse($path, 'a link on the directory path belongs to another identity');
            }
            $target = @readlink($current);
            $resolved = $target === false ? false
               : @realpath($target[0] === '/' ? $target : dirname($current) . '/' . $target);
            if ($resolved === false) {
               return $this->refuse($path, 'a link on the directory path does not resolve');
            }
            $current = $resolved;
            continue;
         }
         if (($mode & 0170000) !== 0040000) {
            return $this->refuse($path, 'a component of the directory path is not a directory');
         }
         // # A directory: root's and private to root — or sticky, where only
         //   its owner may replace what is not theirs: fine unless that owner
         //   is the very identity guarded against (or nobody was named)
         $sticky = ($mode & 0o1000) !== 0;
         $owner = (int) $inode['uid'];
         if ($sticky) {
            // ? Nobody named, or root named (nobody to exempt): every foreign one is refused
            if ($foreign && (self::$guarded === null || self::$guarded === 0 || $owner === self::$guarded)) {
               return $this->refuse($path, 'a sticky directory on the path belongs to the identity guarded against');
            }
         }
         else if ($foreign || ($mode & 0o022) !== 0) {
            return $this->refuse($path, 'a directory on the path can be written by another identity');
         }
         $current = dirname($current);
      }

      // :
      return true;
   }

   /**
    * Open the destination for appending, verified on what was actually opened.
    *
    * `fopen()` FOLLOWS a symbolic link, and a check on the pathname before the
    * open is a window: a link swapped in between the two hands the write to
    * a path somebody else chose — by whoever runs this handler. So the checks
    * are made on the handle: the inode opened must be a regular file with a
    * single name, and the same inode the pathname resolves to after the open.
    * The file is opened without `O_CREAT`, so a dangling link can never make
    * it create the target either; a missing file is created beside the
    * destination under an unguessable name and `link()`ed into place —
    * `link()` fails on any entry already there instead of following it.
    *
    * @param string $path The destination pathname.
    * @return false|resource The handle positioned anywhere (the caller seeks), or false when refused.
    */
   private function open (string $path)
   {
      $directory = dirname($path);
      $privileged = (self::$posix ??= function_exists('posix_getuid')) && posix_getuid() === 0;
      $named = @lstat($path);
      if ($named === false) {
         // ! Absent: create beside under a name nobody can guess, with the
         //   mode the umask gives at creation — never a chmod on a pathname,
         //   which would follow a link — then link into place: `link()` fails
         //   on any entry already there instead of following it.
         try {
            $temporary = "$directory/." . basename($path) . '.' . bin2hex(random_bytes(8));
         }
         catch (Throwable) {
            return $this->refuse($path, 'no randomness for a temporary name');
         }
         $made = @fopen($temporary, 'x');
         if ($made === false) {
            return $this->refuse($path, 'its directory does not accept a new file');
         }
         @fclose($made);
         $linked = @link($temporary, $path);
         @unlink($temporary);
         if ($linked === false && @lstat($path) === false) {
            // ? No hard links on this filesystem: an unprivileged writer may
            //   create in place — `O_EXCL` still follows a dangling link, so
            //   a privileged one may not
            if ($privileged) {
               return $this->refuse($path, 'the file could not be created');
            }
            $created = @fopen($path, 'x');
            if ($created === false) {
               return $this->refuse($path, 'the file could not be created');
            }
            @fclose($created);
         }
      }
      else if (((int) $named['mode'] & 0170000) !== 0100000) {
         // ? Anything but a regular file at the destination is refused, never followed
         return $this->refuse($path, 'the destination is not a regular file');
      }
      else if ($privileged && (int) $named['uid'] !== 0 && (int) $named['uid'] !== (self::$overflow ?? -1)) {
         // ? A privileged writer appends to nobody else's file: in a sticky
         //   directory of root's anybody may have created one under this name
         return $this->refuse($path, 'the destination belongs to another identity');
      }

      // @ Open without O_CREAT, then verify the inode that came out
      $handle = @fopen($path, 'r+');
      if ($handle === false) {
         return $this->refuse($path, 'the file could not be opened');
      }
      $opened = @fstat($handle);
      $named = @lstat($path);
      if (
         $opened === false || $named === false
         || ((int) $opened['mode'] & 0170000) !== 0100000
         || (int) $opened['nlink'] !== 1
         || $opened['dev'] !== $named['dev']
         || $opened['ino'] !== $named['ino']
         || ($privileged && (int) $opened['uid'] !== 0 && (int) $opened['uid'] !== (self::$overflow ?? -1))
      ) {
         @fclose($handle);
         return $this->refuse($path, 'the inode opened is not the regular file the pathname names');
      }

      // :
      return $handle;
   }

   /**
    * The uid a privileged writer may treat as root's because nobody here can
    * be it: the overflow uid, only inside a user namespace, and only while
    * it falls in no mapped range — a rootless container maps 65534 like any
    * other uid, and then it is somebody.
    *
    * @param string $map The process's `uid_map` (`inside outside count` rows).
    * @param int $overflow The kernel's overflow uid.
    * @return int The uid to exempt, or -1 for none.
    */
   public static function exempt (string $map, int $overflow): int
   {
      if ($map === '' || preg_match('/^\s*0\s+0\s+4294967295\s*$/', $map) === 1) {
         return -1;
      }
      // ? A map this parser cannot read exempts nobody
      if (preg_match_all('/^\s*(\d+)\s+\d+\s+(\d+)\s*$/m', $map, $rows, PREG_SET_ORDER) < 1) {
         return -1;
      }
      foreach ($rows as $row) {
         if ($overflow >= (int) $row[1] && $overflow < (int) $row[1] + (int) $row[2]) {
            return -1;
         }
      }

      // : Mapped nowhere: an owner with no identity in this namespace
      return $overflow;
   }

   /**
    * Name the runtime identity a privileged writer must guard against.
    *
    * A root launch that will demote calls it with the identity it demotes to:
    * a sticky directory on the way that belongs to that identity is theirs to
    * empty — the owner of a sticky directory may rename anything in it — and
    * is refused; one belonging to anybody else keeps the sticky protection.
    * Without a named identity every foreign sticky directory is refused.
    *
    * @param int $UID
    */
   public static function guard (int $UID): void
   {
      self::$guarded = $UID;
   }

   /**
    * Keep refusals out of the system logger — the test runner refuses on purpose.
    *
    * @param bool $quiet
    */
   public static function mute (bool $quiet = true): void
   {
      self::$quiet = $quiet;
   }

   /**
    * Refuse a destination — and say so once per path and reason, out of band.
    *
    * A refused sink must not be a silent one: whoever plants a link at the
    * destination would otherwise switch the audit trail off with no signal
    * anywhere. The system logger is the one channel that needs no file — where
    * one listens.
    *
    * @param string $path The destination pathname.
    * @param string $reason Why it was refused.
    * @return false
    */
   private function refuse (string $path, string $reason): false
   {
      if (isset(self::$refused["$path|$reason"]) === false) {
         self::$refused["$path|$reason"] = true;
         // ? The suites refuse on purpose — keep their noise out of the host's journal
         if (self::$quiet === false && (defined('BOOTGLY_ENVIRONMENT') === false || BOOTGLY_ENVIRONMENT !== 'test')) {
            @openlog('bootgly', LOG_PID, LOG_USER);
            @syslog(LOG_WARNING, "Bootgly log sink refused {$path}: {$reason}.");
         }
      }

      return false;
   }
}

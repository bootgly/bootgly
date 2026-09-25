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
use const LOCK_NB;
use const LOCK_UN;
use const LOG_PID;
use const LOG_USER;
use const LOG_WARNING;
use const PREG_SET_ORDER;
use const SEEK_END;
use function basename;
use function bin2hex;
use function clearstatcache;
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
use function hrtime;
use function is_dir;
use function is_link;
use function is_string;
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
use function scandir;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function syslog;
use function trim;
use function unlink;
use function usleep;
use Throwable;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers\File\Rotation;


class File extends Handler
{
   /** Lock attempts per record: a wait cut short by a signal, or a privileged writer's patience run out. */
   private const int ATTEMPTS = 4;
   /** Seconds a privileged writer polls the lock of an inode with a second name before giving up. */
   private const float PATIENCE = 0.2;
   /** Opens per record: how often other writers' rotations may move the name under this one. */
   private const int MOVES = 64;

   // * Config
   public string $path;
   public Rotation $Rotation;

   // * Data
   /** Whether the sink's reports (refusals, failed rotations) stay out of the system logger — see mute(). */
   private static bool $quiet = false;
   /** The runtime identity a privileged writer guards against — see guard(). */
   private static null|int $guarded = null;

   // * Metadata
   /** @var array<string,true> Failures already reported — once per path and reason, per process. */
   private static array $reported = [];
   /** Whether ext-posix is loaded — a privileged writer is only known where it is. */
   private static null|bool $posix = null;
   /** The uid of an owner outside this user namespace, -1 outside one — see trust(). */
   private static null|int $overflow = null;
   /** @var array<string,true> Directories where link() worked — see open(). */
   private static array $linkable = [];
   /** Link races this writer lost on the current record — see open(). */
   private int $races = 0;


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

      // @@ Append under the lock on the inode the name holds — the same lock
      //    every writer and every rotation of this file takes. Nothing here
      //    may raise: a sink that cannot write must never take the process
      //    that logs down with it.
      $privileged = (self::$posix ??= function_exists('posix_getuid')) && posix_getuid() === 0;
      $attempts = 0;
      $moves = 0;
      $this->races = 0;
      $rotated = false;
      while (true) {
         // @ Open — deciding on the inode opened, never on the pathname alone
         $handle = $this->open($path, $moves + 1 >= self::MOVES);
         if ($handle === false) {
            return false;
         }
         if ($handle === null) {
            $moves++;
            continue;
         }

         // ? A wait cut short by a signal — or a privileged writer's patience —
         //   is waited again, on a fresh open (see lock())
         if ($this->lock($handle, $privileged) === false) {
            @fclose($handle);
            if (++$attempts >= self::ATTEMPTS) {
               return $this->refuse($path, 'the file could not be locked');
            }
            continue;
         }

         // ? Under the lock the name must still hold the inode locked: whoever
         //   held it before may have rotated it away — then the new file is opened
         if ($this->verify($path, $handle) === false) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            if (++$moves >= self::MOVES) {
               return $this->refuse($path, 'the file kept moving');
            }
            continue;
         }

         // ? A second name that is a creator's own temporary is an orphan: a
         //   live creator keeps the lock until it has removed it (see open())
         $opened = @fstat($handle);
         if ($opened !== false && (int) $opened['nlink'] > 1) {
            $this->clean($path, $opened);
            $opened = @fstat($handle);
         }

         // ? A privileged writer touches no inode with any other second name —
         //   not even to rotate it: that name may be anybody's
         if ($privileged && ($opened === false || (int) $opened['nlink'] !== 1)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return $this->refuse($path, 'the inode opened is not the regular file the pathname names');
         }

         // @ Rotate when due — once per record: every other writer waits on
         //   this lock and finds the name moved when its turn comes. An
         //   unprivileged writer rotates a due file with a second name too (a
         //   creator killed in its link window): rotating only moves names, and
         //   leaves the next write a new file.
         if ($rotated === false && $this->Rotation->check($path) === true) {
            $rotated = true;
            $this->Rotation->rotate($path);
            if ($this->verify($path, $handle) === false) {
               @flock($handle, LOCK_UN);
               @fclose($handle);
               continue;
            }
            // ? Still here: the rotation failed — keep writing the active file
            $this->report($path, 'the file could not be rotated');
         }

         // ? One name only: an inode with a second name is somebody else's — a
         //   creator lets nobody in before its temporary name is gone (see open())
         $opened = @fstat($handle);
         if ($opened === false || (int) $opened['nlink'] !== 1) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return $this->refuse($path, 'the inode opened is not the regular file the pathname names');
         }
         // @ Append: the handle carries no O_APPEND (see open()), so the end is
         //   sought here, once the lock serialises the writers
         $written = @fseek($handle, 0, SEEK_END) === 0
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
    * are made on the handle: the inode opened must be a regular file, and the
    * same inode the pathname resolves to after the open (its single name is
    * checked under the lock — see write()). The file is opened without
    * `O_CREAT`, so a dangling link can never make it create the target
    * either; a missing file is created beside the destination under an
    * unguessable name, locked, and `link()`ed into place — `link()` fails on
    * any entry already there instead of following it, and the lock keeps
    * every other writer out until the temporary name is gone.
    *
    * @param string $path The destination pathname.
    * @param bool $last Whether this is the last open allowed: a race then refuses instead of opening again.
    * @return false|null|resource The handle positioned anywhere (the caller seeks and locks),
    *                             null when a rotation or a creation moved the name (open again),
    *                             or false when refused.
    */
   private function open (string $path, bool $last)
   {
      $directory = dirname($path);
      $privileged = (self::$posix ??= function_exists('posix_getuid')) && posix_getuid() === 0;
      clearstatcache();
      $named = @lstat($path);
      if ($named === false) {
         // ! Absent: create beside under a name nobody can guess, with the
         //   mode the umask gives at creation — never a chmod on a pathname,
         //   which would follow a link — lock it, then link into place:
         //   `link()` fails on any entry already there instead of following it.
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
         if (@flock($made, LOCK_EX) === false) {
            @fclose($made);
            @unlink($temporary);
            return $this->refuse($path, 'the file could not be locked');
         }
         $linked = @link($temporary, $path);
         @unlink($temporary);
         // ?: Linked: the new file, locked, under its one name
         if ($linked === true) {
            self::$linkable[$directory] = true;
            return $made;
         }
         @fclose($made);

         clearstatcache();
         $named = @lstat($path);
         if ($named === false) {
            // ? No hard links on this filesystem — or, where link() has worked,
            //   another creator's file linked first and already rotated away:
            //   an unprivileged writer may create in place — `O_EXCL` still
            //   follows a dangling link, so a privileged one may not: it opens
            //   again after a lost race — once per record until link() has
            //   worked there, since a second failure in a row means it never does
            if ($privileged) {
               return $last === false && (isset(self::$linkable[$directory]) || $this->races++ < 1)
                  ? null
                  : $this->refuse($path, 'the file could not be created');
            }
            $created = @fopen($path, 'x');
            if ($created === false) {
               clearstatcache();
               return $last === false && @lstat($path) !== false
                  ? null
                  : $this->refuse($path, 'the file could not be created');
            }

            // :
            return $created;
         }
         // # Another writer created it first: open theirs
      }
      if (((int) $named['mode'] & 0170000) !== 0100000) {
         // ? Anything but a regular file at the destination is refused, never followed
         return $this->refuse($path, 'the destination is not a regular file');
      }
      if ($privileged && (int) $named['uid'] !== 0 && (int) $named['uid'] !== (self::$overflow ?? -1)) {
         // ? A privileged writer appends to nobody else's file: in a sticky
         //   directory of root's anybody may have created one under this name
         return $this->refuse($path, 'the destination belongs to another identity');
      }

      // @ Open without O_CREAT, then verify the inode that came out
      $handle = @fopen($path, 'r+');
      if ($handle === false) {
         clearstatcache();
         $now = @lstat($path);
         // ? Rotated away — or replaced — between the check and the open: open again
         return $last === false && ($now === false || $now['dev'] !== $named['dev'] || $now['ino'] !== $named['ino'])
            ? null
            : $this->refuse($path, 'the file could not be opened');
      }
      $opened = @fstat($handle);
      clearstatcache();
      $named = @lstat($path);
      if (
         $opened === false
         || ((int) $opened['mode'] & 0170000) !== 0100000
         || ($privileged && (int) $opened['uid'] !== 0 && (int) $opened['uid'] !== (self::$overflow ?? -1))
      ) {
         @fclose($handle);
         return $this->refuse($path, 'the inode opened is not the regular file the pathname names');
      }
      if ($named === false || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
         @fclose($handle);
         // ? A regular file the name no longer holds, or no name at all: a
         //   rotation moved it — open again; anything else there is refused
         return $last === false && ($named === false || ((int) $named['mode'] & 0170000) === 0100000)
            ? null
            : $this->refuse($path, 'the inode opened is not the regular file the pathname names');
      }

      // :
      return $handle;
   }

   /**
    * Lock the handle for writing.
    *
    * A writer waits for the lock — except a privileged one on an inode with
    * a second name: whoever holds that name could hold its lock for ever. A
    * creator's own second name lasts until it unlinks its temporary (see
    * open()), so a privileged writer polls for at most PATIENCE seconds and
    * waits as anyone would once the inode has one name again.
    *
    * @param resource $handle The handle to lock.
    * @param bool $privileged Whether this writer runs as root.
    * @return bool False when the wait was cut short or the patience ran out.
    */
   private function lock ($handle, bool $privileged): bool
   {
      if ($privileged === false) {
         return @flock($handle, LOCK_EX);
      }

      // @@ Poll while the inode has a second name, then wait
      $until = hrtime(true) + (int) (self::PATIENCE * 1_000_000_000);
      while (true) {
         $opened = @fstat($handle);
         if ($opened !== false && (int) $opened['nlink'] === 1) {
            return @flock($handle, LOCK_EX);
         }
         if (@flock($handle, LOCK_EX | LOCK_NB) === true) {
            return true;
         }
         if (hrtime(true) >= $until) {
            return false;
         }
         usleep(1_000);
      }
   }

   /**
    * Remove the orphaned temporary name a creator left on the inode.
    *
    * A creator links its temporary into place and unlinks it before it
    * releases the lock (see open()); one killed in between leaves the file
    * with a second name, and no writer would accept it again. Holding the
    * lock, a name beside the destination in the creator's own pattern that
    * names the same inode can only be such an orphan.
    *
    * @param string $path The destination pathname.
    * @param array<int|string,int> $opened The locked handle's fstat.
    */
   private function clean (string $path, array $opened): void
   {
      $directory = dirname($path);
      $prefix = '.' . basename($path) . '.';
      foreach ((array) @scandir($directory) as $name) {
         if (
            is_string($name) === false
            || str_starts_with($name, $prefix) === false
            || preg_match('/^[0-9a-f]{16}$/', substr($name, strlen($prefix))) !== 1
         ) {
            continue;
         }
         $named = @lstat("$directory/$name");
         if (
            $named !== false
            && ((int) $named['mode'] & 0170000) === 0100000
            && $named['dev'] === $opened['dev']
            && $named['ino'] === $opened['ino']
         ) {
            @unlink("$directory/$name");
         }
      }
   }

   /**
    * Verify, under the lock, that the name still holds the inode locked.
    *
    * @param string $path The destination pathname.
    * @param resource $handle The handle locked.
    * @return bool False when a rotation moved the name away meanwhile.
    * @phpstan-impure
    */
   private function verify (string $path, $handle): bool
   {
      clearstatcache();
      $named = @lstat($path);
      $opened = @fstat($handle);

      // :
      return $named !== false && $opened !== false
         && $named['dev'] === $opened['dev']
         && $named['ino'] === $opened['ino'];
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
    * Keep the sink's reports out of the system logger: refusals, and rotations
    * that failed while the write went through — the test runner refuses on purpose.
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
    * anywhere.
    *
    * @param string $path The destination pathname.
    * @param string $reason Why it was refused.
    * @return false
    */
   private function refuse (string $path, string $reason): false
   {
      $this->report($path, $reason, refused: true);

      return false;
   }

   /**
    * Report a sink failure once per path and reason, out of band.
    *
    * The system logger is the one channel that needs no file — where one
    * listens.
    *
    * @param string $path The destination pathname.
    * @param string $reason What failed.
    * @param bool $refused Whether the destination was refused (nothing written).
    */
   private function report (string $path, string $reason, bool $refused = false): void
   {
      if (isset(self::$reported["$path|$reason"]) === false) {
         self::$reported["$path|$reason"] = true;
         // ? The suites fail on purpose — keep their noise out of the host's journal
         if (self::$quiet === false && (defined('BOOTGLY_ENVIRONMENT') === false || BOOTGLY_ENVIRONMENT !== 'test')) {
            $verb = $refused ? ' refused' : '';
            @openlog('bootgly', LOG_PID, LOG_USER);
            @syslog(LOG_WARNING, "Bootgly log sink{$verb} {$path}: {$reason}.");
         }
      }
   }
}

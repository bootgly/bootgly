<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints;


use const BOOTGLY_STORAGE_DIR;
use function chdir;
use function class_exists;
use function clearstatcache;
use function closedir;
use function getcwd;
use function in_array;
use function is_array;
use function lchgrp;
use function lchown;
use function lstat;
use function mkdir;
use function opendir;
use function posix_getgrnam;
use function posix_getpwnam;
use function posix_getuid;
use function readdir;
use function register_shutdown_function;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File as FileHandler;
use Bootgly\ACI\Logs\Handlers\Memory as MemoryHandler;
use Bootgly\ACI\Logs\Handlers\Syslog as SyslogHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;


/**
 * Privilege handoff for a server launched as root with a runtime `user`.
 *
 * Whatever a root process must never do on behalf of the identity it will
 * become lives here. The global log sinks — the project's own, or the Daemon
 * fallback installed when none is configured — are HELD in memory while root
 * runs and installed by settle() only after the privileges are dropped, by the
 * master and by each worker, with the held records replayed through them. The
 * storage directory those sinks default to is prepared and handed over by
 * inode, never through a link, and root never names a file inside it.
 *
 * The Daemon fallback stands in only for sinks nobody configured: one a
 * platform shell registers after configure() — the Web App pushes its own
 * File sink at the fallback's very path — takes its place, notice included,
 * so no record is ever persisted twice (see retire()).
 *
 * The using class carries `$Mode`, `$user`, `$group`, `$Logger` and `$Process`,
 * and calls store() as soon as its transport is configured (the runtime `user`
 * is known from then on) and at the top of start(), inherit() right after it
 * detaches, and settle() right after `posix_setuid()`.
 */
trait Demotable
{
   // * Metadata
   // # Sinks a root launch withholds until settle() — see store()
   /** The global sinks to install once privileges are dropped. */
   private null|Handlers $Sinks = null;
   /** The notice naming where the fallback sink writes — the first record the master persists. */
   private null|string $notice = null;
   /** The fallback File sink store() installed for a Daemon with no sinks configured — see retire(). */
   private null|FileHandler $Fallback = null;
   /** What a root launch found wrong with `storage/logs` — said even when the fallback yields. */
   private null|string $issue = null;
   /** Whether store() already decided — it runs once, as soon as the transport is configured. */
   private bool $stored = false;


   /**
    * Install the global log sinks — now, or after the privileges are dropped.
    *
    * With no sinks configured, a Daemon has no terminal and every server
    * record would be silently dropped at the Logger entry guard: one File sink
    * (JSON lines, default rotation) at `storage/logs/{channel}.log` is the
    * fallback, and a notice naming it is the first record start() persists —
    * written by start()'s own call, before its first record, or by the
    * master of a root launch right after it demotes, before it replays what it
    * held. A project that configured `Logger::$Sinks` keeps them, and the
    * fallback stands in only until start(): a sink registered in between — the
    * Web App's own File sink, at that very path — takes its place, notice
    * included (see retire()). Either way, on a root launch with a runtime
    * `user` NO root process ever holds a file sink: the file root would
    * create — through whatever the runtime identity left at that pathname
    * since the last boot handed it the directory — is exactly the write a
    * compromised worker wants root to make, and any check on the pathname
    * before that write is a window. Until
    * settle() the records are held in memory; root only prepares the
    * DIRECTORY, verified to be a real one, and hands that over. Only the
    * File sink re-opens its file on every write, as whoever writes: a
    * handler that captured a descriptor while root ran (a Stream opened in
    * `boot()`) is withheld and installed like any other, but keeps writing
    * through root's descriptor.
    *
    * @param bool $starting Whether start() is the caller: the fallback is then
    *                       confirmed, and a launch that keeps its identity says
    *                       the notice. A later configure() never says it.
    */
   protected function store (bool $starting = false): void
   {
      // ? Decided at configure(); at every later call the fallback yields to
      //   sinks registered since, and at start() a launch that keeps its
      //   identity says the notice — before start()'s first record, once the
      //   fallback is confirmed
      if ($this->stored) {
         $this->retire();
         if ($starting && $this->Sinks === null && $this->notice !== null) {
            $this->Logger->log(notice: $this->notice);
            $this->notice = null;
         }

         return;
      }
      $this->stored = true;

      $Sinks = Logger::$Sinks;
      $notice = null;

      // ! Daemon fallback — one JSON file per channel under the storage dir
      if ($Sinks === null && $this->Mode === Modes::Daemon) {
         $path = BOOTGLY_STORAGE_DIR . 'logs/{channel}.log';
         $this->Fallback = new FileHandler($path);
         $Sinks = new Handlers;
         $Sinks->push($this->Fallback);
         $notice = "No global log sinks configured — Daemon logs will persist to $path@.;";
      }
      // ? Nothing to install
      if ($Sinks === null) {
         return;
      }
      // ? Not root, or root that stays root: the identity that installs the
      //   sinks is the one that keeps writing through them — the notice waits
      //   for start(), where the fallback is confirmed or yields
      if (posix_getuid() !== 0 || $this->user === null) {
         Logger::$Sinks = $Sinks;
         $this->notice = $notice;

         return;
      }

      // ? Another server in this process already holds — known by the hold's
      //   IDENTITY, never by class (a project may push a Memory handler of its
      //   own): share what it withheld, so whichever server settles first
      //   installs the real sinks, and never nest a hold
      $Hold = MemoryHandler::hold();
      if ($Hold !== null && in_array($Hold, $Sinks->Handlers, true)) {
         $this->Sinks = new Handlers;
         foreach ($Hold->Withheld as $Handler) {
            $this->Sinks->push($Handler);
         }

         return;
      }

      // ! A root launch demotes later: hold the records until then
      $this->Sinks = $Sinks;
      $Hold = new MemoryHandler;
      MemoryHandler::hold($Hold, $Sinks->Handlers);
      Logger::$Sinks = new Handlers;
      Logger::$Sinks->push($Hold);
      // ! The rescue below may run demoted with the hold still in place — a
      //   server that held nothing settled instead — and nothing may be
      //   loaded anew then: the system logger and its formatter are loaded now
      class_exists(SyslogHandler::class);
      class_exists(Line::class);
      class_exists(Display::class);

      // ! Should this process end before settle() — a demotion that fails, a
      //   bind refused — the hold must not die with it: the system logger,
      //   which needs no file, gets the notice and the records. The launcher
      //   still displays its own; only a detached process is otherwise mute.
      register_shutdown_function(function (): void {
         if ($this->Sinks === null || Display::$segments !== Display::NONE) {
            return;
         }
         // ? Another server settled: every held record reached the real
         //   sinks — only this server's notice is pending, and it goes where
         //   the records went (nothing is loaded anew after the drop: the
         //   framework tree may not be readable by the runtime identity)
         $Hold = MemoryHandler::hold();
         if ($Hold === null) {
            if ($this->notice !== null && $this->Process->level === 'master') {
               $this->Logger->log(notice: $this->notice);
            }

            return;
         }
         // @ Still held: no server settled this hold — the notice and the
         //   records go to the system logger, loaded while root ran
         $Syslog = new SyslogHandler;
         if ($this->notice !== null) {
            $Syslog->handle(new Record(Levels::Notice, $this->Logger->channel, $this->notice));
         }
         $Hold->replay($Syslog);
      });

      // @ Prepare storage/logs and hand it over — by inode, never through a link
      $userInfo = posix_getpwnam($this->user);
      if ($userInfo === false) {
         if ($notice !== null) {
            $this->Logger->log(notice: $notice);
         }

         return; // demote() reports the unknown user and exits
      }
      $UID = (int) $userInfo['uid'];
      $GID = (int) $userInfo['gid'];
      if ($this->group !== null) {
         $groupInfo = posix_getgrnam($this->group);
         if ($groupInfo !== false) {
            $GID = (int) $groupInfo['gid'];
         }
      }
      // ! The file sink now knows whom a privileged write must guard against —
      //   `root` as the runtime identity names nobody to exempt
      if ($UID !== 0) {
         FileHandler::guard($UID);
      }

      $directory = BOOTGLY_STORAGE_DIR . 'logs';
      $entry = @lstat($directory);
      $created = false;
      if ($entry === false) {
         // ! Only the one directory — an ancestor root created would stay
         //   root's, unreported, and the storage root is the deployment's
         $created = @mkdir($directory, 0o775);
         $entry = @lstat($directory);
      }
      $issue = null;
      if ($entry === false) {
         // ? Nothing there and nothing could be made there — the storage
         //   root itself is missing; the demoted sink will say what it can
         $issue = "storage/logs could not be created: is the storage directory there?@.;";
      }
      else if (((int) $entry['mode'] & 0170000) === 0120000) {
         // ? A link where the directory should be: root follows nothing and
         //   hands nothing over — whoever created the link chose where the
         //   runtime identity writes, and its target must already be writable
         //   by that identity. A link to a directory of root's would otherwise
         //   let the runtime identity pick which directory of root's changes
         //   hands, through any component it controls along the way.
         $issue = "storage/logs is a link and was left as found: its target must be writable by {$this->user}.@.;";
      }
      else if (((int) $entry['mode'] & 0170000) !== 0040000) {
         // ? An inode of another kind where the directory should be: root
         //   touches nothing under it. The sinks are still installed at
         //   settle() — the runtime identity may be able to write there on
         //   its own — and File::write() refuses anything but a regular file.
         $issue = "storage/logs was not handed to {$this->user}: not a plain directory.@.;";
      }
      else if ($created === false && (int) $entry['uid'] !== $UID) {
         // ? A directory this launch did not create and that is not the
         //   runtime identity's — root's from before, or anybody else's: of
         //   unknown provenance. The runtime identity may own the name it
         //   sits under and can rename any tree onto it, so it is left as found
         $issue = "storage/logs is not {$this->user}'s and was left as found: make it {$this->user}'s yourself.@.;";
      }
      else if ($this->cede($directory, (int) $entry['dev'], (int) $entry['ino'], $UID, $GID, $created) === false) {
         // ? The name came to resolve elsewhere between the decision and the
         //   handover, or the working directory could not be kept: nothing
         //   changed hands
         $issue = "storage/logs was not handed to {$this->user}: it could not be entered as decided.@.;";
      }
      // ? Said even when the project brought its own sinks — it is the one
      //   signal the operator gets when those sinks cannot write there
      if ($issue !== null) {
         $notice = $notice === null ? $issue : "$notice — $issue";
      }

      // ! The notice waits for settle(): the daemon master is itself forked
      //   from the launcher that runs store(), and a fork never carries its
      //   parent's hold — so the master writes it, first, as the runtime identity
      $this->issue = $issue;
      $this->notice = $notice;
   }

   /**
    * Let the fallback yield to the sinks registered since store() decided.
    *
    * store() decides at configure(), and a platform shell registers its own
    * sinks after that — the Web App pushes a File sink at the fallback's very
    * path between configure() and start(). Those are the configuration
    * store() could not see: the fallback and its notice give way to whatever
    * was pushed beside it — beside the hold, on a root launch, where the
    * newcomers are withheld in the fallback's place, never live as root.
    * Called by store() at start(), before its first record, and by settle(),
    * before the withheld sinks are installed. A project that configured its
    * own sinks has no fallback, and keeps them all. `Logger::$Sinks` is
    * replaced, never mutated: read the static, do not cache the collection.
    */
   private function retire (): void
   {
      // ? No fallback in place
      if ($this->Fallback === null) {
         return;
      }
      // ? Nothing registered beside the fallback — or beside the hold
      $Hold = MemoryHandler::hold();
      $Installed = [];
      foreach (Logger::$Sinks->Handlers ?? [] as $Handler) {
         if ($Handler !== $this->Fallback && $Handler !== $Hold) {
            $Installed[] = $Handler;
         }
      }
      if ($Installed === []) {
         return;
      }

      // @ The fallback and its notice give way — what a root launch found
      //   wrong with storage/logs is still said
      $this->Fallback = null;
      $this->notice = $this->issue;
      $Sinks = new Handlers;
      foreach ($Installed as $Handler) {
         $Sinks->push($Handler);
      }
      // ? Installed at configure(): the newcomers are the live sinks now
      if ($this->Sinks === null) {
         Logger::$Sinks = $Sinks;

         return;
      }
      // @ Withheld from root: the newcomers take the fallback's place in the
      //   hold — withheld like it, never live as root — and only the hold stays
      $this->Sinks = $Sinks;
      if ($Hold !== null) {
         MemoryHandler::hold($Hold, $Sinks->Handlers);
         Logger::$Sinks = new Handlers;
         Logger::$Sinks->push($Hold);
      }
   }

   /**
    * Keep the hold a detached master inherited from its launcher.
    *
    * A fork starts with an empty hold — a worker must never replay what the
    * master will — but the daemon master is itself forked from the launcher
    * that ran store(), and nobody else will persist what the launcher held
    * (`Starting Server…`): called right after detach(), before any record.
    */
   protected function inherit (): void
   {
      // ! The hold only — by identity: a Memory handler of the project's own
      //   in the sinks starts empty in the master like in any other fork
      MemoryHandler::hold()?->adopt();
   }

   /**
    * Install the sinks store() withheld — now, as the runtime identity.
    *
    * Called right after `posix_setuid()` by every process that demotes: the
    * master and each worker install their own, since the static never crosses
    * a fork after this point, write the fallback notice (the master only) and
    * replay what THIS process held while it still ran as root — a forked
    * worker holds nothing of its parent's. The file is therefore created by
    * the owner that keeps it, and never chown()ed.
    */
   protected function settle (): void
   {
      // ! The fallback yields to whatever was registered beside the hold since —
      //   retire() may replace the withheld set, so it is read after
      $this->retire();
      $Sinks = $this->Sinks;
      if ($Sinks === null) {
         return;
      }

      // ! Whatever was pushed beside the hold since store() rides along —
      //   the hold itself, known by identity, does not
      $Hold = MemoryHandler::hold();
      foreach (Logger::$Sinks->Handlers ?? [] as $Handler) {
         if ($Handler !== $Hold && in_array($Handler, $Sinks->Handlers, true) === false) {
            $Sinks->push($Handler);
         }
      }
      Logger::$Sinks = $Sinks;
      $this->Sinks = null;

      // @ The fallback notice: the master's first record, before anything it held
      if ($this->notice !== null && $this->Process->level === 'master') {
         $this->Logger->log(notice: $this->notice);
      }
      $this->notice = null;

      // @ The hold, if this process still carries one, is replayed and released
      if ($Hold !== null) {
         $Hold->replay(...$Sinks->Handlers);
         MemoryHandler::release();
      }
   }

   /**
    * Hand the directory root decided on to the runtime identity — as the
    * inode it decided on, never as a name.
    *
    * The name `storage/logs` lives in a directory the runtime identity may
    * own (the kit lays `storage/` down as the deploy user), so a pathname is
    * theirs to swap at any moment — and a rename inside the same parent
    * needs no permission on what is renamed, so any tree of root's can be
    * put under that name — even between the `mkdir` and the `lstat` that
    * decides. Root therefore gives away only what it created in this very
    * launch: it enters the directory once, proves `.` is the inode it decided
    * on and, when it just created it, that `.` is still the EMPTY directory
    * `mkdir` returned — a tree swapped in under the name is never empty —
    * then hands `.` over. Nothing under it is walked or touched.
    *
    * @param string $directory The pathname to enter.
    * @param int $dev The device of the inode the caller decided on.
    * @param int $ino Its inode number.
    * @param int $UID
    * @param int $GID
    * @param bool $fresh Whether this launch created the directory — it must then still be empty.
    *
    * @return bool Whether the directory changed hands.
    */
   private function cede (string $directory, int $dev, int $ino, int $UID, int $GID, bool $fresh): bool
   {
      $previous = getcwd();
      if ($previous === false || @chdir($directory) === false) {
         return false;
      }
      try {
         // ? The inode entered must be the one decided on — never a name
         //   that came to resolve elsewhere in between
         clearstatcache(true);
         $here = @lstat('.');
         if (is_array($here) === false || (int) $here['dev'] !== $dev || (int) $here['ino'] !== $ino) {
            return false;
         }
         // ? Created by this launch: still empty, or the name was swapped
         //   for a tree of somebody's choosing before the decision was read.
         //   Read entry by entry — a swapped-in tree may be huge — and stop
         //   at the first one beyond `.` and `..`
         if ($fresh) {
            $listing = @opendir('.');
            if ($listing === false) {
               return false;
            }
            $empty = true;
            while (($name = readdir($listing)) !== false) {
               if ($name !== '.' && $name !== '..') {
                  $empty = false;

                  break;
               }
            }
            closedir($listing);
            if ($empty === false) {
               return false;
            }
         }

         // :
         return $this->hand('.', $UID, $GID, 0040000);
      }
      finally {
         // ! Back where the launcher was — by name, the only way there is —
         //   and never left inside what was just given away
         if (@chdir($previous) === false) {
            @chdir('/');
         }
      }
   }

   /**
    * Hand one inode root created — or already owns — to the runtime identity.
    *
    * `lstat` first, and the inode must be of the expected kind: `chown()`
    * FOLLOWS a symbolic link, so a link the runtime identity left at that
    * pathname would hand it the target instead. `lchown()`/`lchgrp()` act on
    * the link itself, and the closing `lstat` proves the same inode came out
    * the other side, owned by whom it should be.
    *
    * @param string $path
    * @param int $UID
    * @param int $GID
    * @param int $kind The `S_IFMT` bits the inode must carry — `0040000` a directory, `0140000` a socket.
    *
    * @return bool
    */
   protected function hand (string $path, int $UID, int $GID, int $kind): bool
   {
      $before = @lstat($path);
      if (is_array($before) === false || ((int) $before['mode'] & 0170000) !== $kind) {
         return false;
      }
      if (@lchown($path, $UID) === false || @lchgrp($path, $GID) === false) {
         return false;
      }
      $after = @lstat($path);

      // :
      return is_array($after)
         && $after['dev'] === $before['dev']
         && $after['ino'] === $before['ino']
         && ((int) $after['mode'] & 0170000) === $kind
         && (int) $after['uid'] === $UID
         && (int) $after['gid'] === $GID;
   }
}

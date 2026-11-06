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
use const DIRECTORY_SEPARATOR;
use function basename;
use function chdir;
use function class_exists;
use function clearstatcache;
use function closedir;
use function decoct;
use function dirname;
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
use function realpath;
use function register_shutdown_function;
use function rmdir;
use function rtrim;
use function str_replace;

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
    * The ancestors provision() already warned about in this launch, as `<directory>:<ancestor>` — said once per directory.
    * @var array<string,true>
    */
   private array $ancestors = [];


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

      // ! The sinks still install at settle() whatever is found — the runtime
      //   identity may be able to write there on its own
      $issue = $this->decide('logs', 0o775, $UID, $GID);
      if ($issue !== null) {
         $issue = "{$issue}@.;";
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
    * Prepare the storage directories the demoted workers write, before any of
    * them is forked — or refuse the launch.
    *
    * A worker runs as the runtime identity from its first request on, and
    * what it writes under the storage root — the session key and records —
    * must be that identity's before it needs them. On a root launch with a
    * runtime `user`, each directory goes through the same decision as
    * `storage/logs`: one this launch creates is handed over by inode, and one
    * the runtime identity already owns is used as is. Anything else is never
    * given away, and the workers could only answer errors from it, so the
    * launch is refused instead, naming the directory and the remedy: a
    * directory of unknown provenance root did not create, an inode of another
    * kind, a link (a worker's directory is the runtime identity's own, never
    * a target someone else chose), or one opener than the mode its workers
    * require. An ancestor the session store's own path checks refuse is said
    * in a warning, not refused: the server still starts.
    *
    * @param array<string,int> $directories Directory name under the storage root (`{uid}` is the runtime uid) => the mode it is created with.
    * @param bool $strict Whether a directory that cannot be prepared refuses the launch; otherwise it is said in a warning (the workers keep what goes there in a private temporary directory).
    *
    * @return null|string Why the launch must be refused, or `null` to go on.
    */
   protected function provision (array $directories, bool $strict = true): null|string
   {
      // ? Not root, or root that stays root: whoever runs keeps writing
      if ($directories === [] || posix_getuid() !== 0 || $this->user === null) {
         return null;
      }

      // !
      $userInfo = posix_getpwnam($this->user);
      if ($userInfo === false) {
         return null; // demote() reports the unknown user and exits
      }
      $UID = (int) $userInfo['uid'];
      $GID = (int) $userInfo['gid'];
      if ($this->group !== null) {
         $groupInfo = posix_getgrnam($this->group);
         if ($groupInfo !== false) {
            $GID = (int) $groupInfo['gid'];
         }
      }

      // @@
      foreach ($directories as $name => $mode) {
         // ! A per-identity directory names the runtime uid (`cache-{uid}`)
         $name = str_replace('{uid}', (string) $UID, $name);
         $path = BOOTGLY_STORAGE_DIR . $name;

         $refusal = null;
         $issue = $this->decide($name, $mode, $UID, $GID);
         if ($issue !== null) {
            $entry = @lstat($path);
            // ? A link: a worker's directory is never a target somebody chose
            if (is_array($entry) && ((int) $entry['mode'] & 0170000) === 0120000) {
               $refusal = "storage/{$name} is a link: it must be a plain directory of {$this->user}'s, and root follows no link to make it one. Replace the link with that directory before starting.";
            }
            else {
               // ? A directory of somebody else's: the one case a chown fixes
               $foreign = is_array($entry)
                  && ((int) $entry['mode'] & 0170000) === 0040000
                  && (int) $entry['uid'] !== $UID;
               $refusal = $foreign
                  ? "{$issue} The workers run as {$this->user} and write there: `chown -R {$this->user}: {$path}` before starting."
                  : $issue;
            }
         }
         else {
            // ? The runtime identity's, but opener than its workers require: the
            //   session handler refuses a group/world-writable key directory
            $entry = @lstat($path);
            $open = is_array($entry) ? (int) $entry['mode'] & 0o022 & ~$mode : 0;
            if ($open !== 0) {
               $found = decoct((int) $entry['mode'] & 0o777);
               $wanted = decoct($mode);
               $refusal = "storage/{$name} is group/world-writable ({$found}): its workers require {$wanted}. Run `chmod {$wanted} {$path}` as {$this->user} — it owns it — before starting.";
            }
         }
         if ($refusal !== null) {
            // ?: A directory the workers cannot do without
            if ($strict) {
               return $refusal;
            }
            // ? One they can: said, and they make do elsewhere
            $this->Logger->log(warning: "@\;{$refusal} Until then the workers keep it in a private temporary directory.@\;");

            continue;
         }

         // ? An ancestor the session store's own checks refuse: said, not
         //   refused (a host-owned bind mount is the documented container
         //   layout), since what lives under it may still work
         //   — a cache tree follows a link it can trust (Config::locate()
         //   resolves the storage root), the session store never does
         $sessions = $name === 'sessions';
         $found = $this->inspect($path, $UID, links: $sessions === false);
         if ($found !== null && isSet($this->ancestors["{$name}:{$found[0]}"]) === false) {
            [$ancestor, $reason] = $found;
            $this->ancestors["{$name}:{$ancestor}"] = true;
            $fails = $sessions
               ? 'the session store refuses such a path, so every session access will fail'
               : "the workers' caches cannot be kept there and move to a private temporary directory";
            $cause = match ($reason) {
               'link' => "is a symbolic link — {$fails}: point the storage directory at the real path",
               'kind' => "is not a directory — {$fails}",
               'owner' => "is owned by neither root nor {$this->user} — {$fails}. Give it to root: `chown root: {$ancestor}`",
               default => "is group/world-writable without the sticky bit — {$fails}. `chmod go-w {$ancestor}`",
            };
            $this->Logger->log(
               warning: "@\;storage/{$name}: its ancestor {$ancestor} {$cause}.@\;"
            );
         }
      }

      // :
      return null;
   }

   /**
    * Find the first ancestor of a worker directory that another identity
    * could replace — the rule the session store applies to its own path
    * (`Session\Handlers\Cache::secure()`): a plain directory owned by root
    * (uid 0, or the owner of `/`) or by the runtime identity, and not
    * group/world-writable unless it carries the sticky bit.
    *
    * @param string $path The worker directory.
    * @param int $UID The runtime identity.
    * @param bool $links Whether a link of root's or the runtime identity's is walked through — and then where it leads — (the cache trees: their storage root is resolved) instead of refused (the session store).
    *
    * @return null|array{0:string,1:string} The offending ancestor and why (`link`, `kind`, `owner` or `mode`), or `null` when every one is safe.
    */
   private function inspect (string $path, int $UID, bool $links = false): null|array
   {
      // ! Root is uid 0 — and whoever owns `/` (a user namespace shows the
      //   host's root as an overflow uid there)
      $rootState = @lstat(DIRECTORY_SEPARATOR);
      $rootUID = is_array($rootState) ? (int) $rootState['uid'] : 0;

      // @@
      $ancestor = dirname(rtrim($path, DIRECTORY_SEPARATOR));
      while (true) {
         $state = @lstat($ancestor);
         $type = is_array($state) ? (int) $state['mode'] & 0170000 : 0;
         $mode = is_array($state) ? (int) $state['mode'] : 0;
         // ?:
         if ($type === 0120000 && $links === false) {
            return [$ancestor, 'link'];
         }
         if (is_array($state) === false || ($type !== 0040000 && $type !== 0120000)) {
            return [$ancestor, 'kind'];
         }
         if (in_array((int) $state['uid'], [0, $rootUID, $UID], true) === false) {
            return [$ancestor, 'owner'];
         }
         if ($type === 0040000 && ($mode & 0o022) !== 0 && ($mode & 0o1000) === 0) {
            return [$ancestor, 'mode'];
         }

         $parent = dirname($ancestor);
         if ($parent === $ancestor) {
            break;
         }
         $ancestor = $parent;
      }

      // ?: A link walked through: where it leads is held to the same rule —
      //    the chain Config::locate() resolves and then trusts
      $written = dirname(rtrim($path, DIRECTORY_SEPARATOR));
      $resolved = $links ? realpath($written) : false;
      if ($resolved !== false && $resolved !== $written) {
         return $this->inspect($resolved . DIRECTORY_SEPARATOR . basename($path), $UID);
      }

      // :
      return null;
   }

   /**
    * Decide one directory under the storage root for the runtime identity —
    * create it when absent and hand over what this launch created, by inode.
    *
    * @param string $name The directory under the storage root.
    * @param int $mode The mode it is created with when absent.
    * @param int $UID The runtime identity.
    * @param int $GID Its group.
    *
    * @return null|string What keeps the directory from being the runtime identity's, or `null` when it is.
    */
   private function decide (string $name, int $mode, int $UID, int $GID): null|string
   {
      $directory = BOOTGLY_STORAGE_DIR . $name;
      $entry = @lstat($directory);
      $created = false;
      if ($entry === false) {
         // ! Only the one directory — an ancestor root created would stay
         //   root's, unreported, and the storage root is the deployment's
         $created = @mkdir($directory, $mode);
         $entry = @lstat($directory);
      }

      if ($entry === false) {
         // ?: Nothing there and nothing could be made there — the storage
         //    root itself is missing
         return "storage/{$name} could not be created: is the storage directory there?";
      }
      if (((int) $entry['mode'] & 0170000) === 0120000) {
         // ?: A link where the directory should be: root follows nothing and
         //    hands nothing over — whoever created the link chose where the
         //    runtime identity writes, and its target must already be writable
         //    by that identity. A link to a directory of root's would otherwise
         //    let the runtime identity pick which directory of root's changes
         //    hands, through any component it controls along the way.
         return "storage/{$name} is a link and was left as found: its target must be writable by {$this->user}.";
      }
      if (((int) $entry['mode'] & 0170000) !== 0040000) {
         // ?: An inode of another kind where the directory should be: root
         //    touches nothing under it
         return "storage/{$name} was not handed to {$this->user}: not a plain directory.";
      }
      if ($created === false) {
         // ?: Already the runtime identity's: used as is — never chowned again
         //    (a root that may not chown it, an NFS root_squash, keeps working)
         if ((int) $entry['uid'] === $UID) {
            return null;
         }

         // ?: A directory this launch did not create and that is not the
         //    runtime identity's — root's from before, or anybody else's: of
         //    unknown provenance. The runtime identity may own the name it
         //    sits under and can rename any tree onto it, so it is left as found
         return "storage/{$name} is not {$this->user}'s and was left as found: make it {$this->user}'s yourself.";
      }
      if ($this->cede($directory, (int) $entry['dev'], (int) $entry['ino'], $UID, $GID, $created) === false) {
         // ?: The name came to resolve elsewhere between the decision and the
         //    handover, or the working directory could not be kept: nothing
         //    changed hands. What this launch created goes again — removed
         //    only while empty — so the next launch never blames the operator
         //    for a root-owned directory the framework made
         @rmdir($directory);

         return "storage/{$name} was not handed to {$this->user}: it could not be entered as decided.";
      }

      // :
      return null;
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

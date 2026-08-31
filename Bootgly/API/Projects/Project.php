<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Projects;


use const DEBUG_BACKTRACE_IGNORE_ARGS;
use function basename;
use function debug_backtrace;
use function define;
use function defined;
use function dirname;
use function is_dir;
use function is_file;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use Closure;
use Error;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Configs;
use Bootgly\API\Projects\Project\Events;


/**
 * Represents a Bootgly project.
 *
 * The constructor captures metadata and derives path/folder from the caller.
 * Registration (defining the BOOTGLY_PROJECT constant and adding to Projects)
 * happens only when boot() is called.
 *
 * Every project MUST declare whether it is exportable: exportable projects
 * appear in the `bootgly project create` import picker; private ones do not.
 *
 * Only one Project can be booted per process. Attempting to boot a second
 * Project throws a fatal Error.
 */
class Project
{
   // * Config
   // # Explicit
   public bool $exportable;
   public string $name;
   public string $description;
   public string $version;
   public string $author;
   // # Implicit
   public readonly string $path;
   public string $folder;

   // * Data
   public Closure $boot;
   public null|Configs $Configs = null;

   // * Metadata
   protected bool $booted = false;
   // ? Handoff mark — set by a process whose exit merely hands the project
   //   over (a daemonize launcher, a reload exec, a forked helper child), so
   //   its teardown never announces `Project.Shutdown`
   public bool $detached = false;


   /**
    * @param Closure $boot Boot function executed by `bootgly project <Name> start` —
    *                      receives the command `$arguments` and `$options`.
    * @param bool $exportable Whether the project appears in the `bootgly project create`
    *                         import picker to be copied into user workspaces.
    * @param string $name Human-readable project name (e.g. `My App`).
    * @param string $folder Projects-root-relative folder override (e.g. `App/API`).
    *                       Derived from the signature file location when omitted.
    * @param string $description Short description shown by `bootgly project list`.
    * @param string $version Project version — plain semver (e.g. `1.0.0`).
    * @param string $author Project author (person or organization).
    */
   public function __construct (
      // * Data (required)
      Closure $boot,
      // * Config (required)
      bool $exportable,
      // * Config (optional)
      string $name = '',
      string $folder = '',
      string $description = '',
      string $version = '',
      string $author = '',
   )
   {
      // * Config
      // # Implicit
      /** @var string $callerFile */
      $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
      $dir = dirname($callerFile);
      $this->path = $dir . '/';
      // @ Derive the projects-root-relative path (e.g. Demo/HTTP_Server_CLI) — the canonical id
      $base = rtrim(Projects::CONSUMER_DIR, '/');
      if (str_starts_with($dir, $base) === false) {
         $base = rtrim(Projects::AUTHOR_DIR, '/');
      }
      $relative = str_starts_with($dir, $base)
         ? trim(substr($dir, strlen($base)), '/')
         : basename($dir);
      $this->folder = $folder !== '' ? $folder : $relative;
      // # Explicit
      $this->exportable = $exportable;
      $this->name = $name;
      $this->description = $description;
      $this->version = $version;
      $this->author = $author;

      // * Data
      $this->boot = $boot;
   }

   /**
    * Mount the project environment without running its entry closure.
    *
    * Defines the BOOTGLY_PROJECT constant, registers in Projects, stamps the log
    * provenance and loads configs, i18n catalogs and the per-project Composer
    * autoloader — everything a worker command (`project <Name> schedule run`)
    * needs to execute project code, minus the boot entry (which starts the app
    * or server and, for servers, never returns). The Boot/Shutdown events stay
    * paired with the entry lifecycle: a mounted-only process emits neither.
    * A second mount (or boot) in the same process throws a fatal Error.
    */
   public function mount (): void
   {
      // ? Register (once per process)
      if ( defined('BOOTGLY_PROJECT') ) {
         throw new Error(
            'Only one Project can be booted per process. '
            . 'BOOTGLY_PROJECT is already defined.'
         );
      }

      define('BOOTGLY_PROJECT', $this);
      Projects::add($this);

      // @ Stamp log provenance for this process — every Record built from here on
      //   carries this project's canonical id: the projects-root-relative folder,
      //   the same identity `project start/stop/logs --project` address
      //   (ACI must never read BOOTGLY_PROJECT itself)
      Record::$provenance = $this->folder !== '' ? $this->folder : $this->name;

      // @ Configs
      $configsDir = "{$this->path}configs/";
      if (is_dir($configsDir)) {
         $this->Configs = new Configs($configsDir);
      }

      // @ Catalogs (i18n) — convention: {project}/catalogs/{locale}/{domain}.php
      $catalogsDir = "{$this->path}catalogs";
      if (is_dir($catalogsDir)) {
         Language::load($catalogsDir);
      }

      // @ Per-project Composer autoload — the fallback for boots that never
      //   pass through a require site (tests, embedded boots); the inline
      //   include keeps this entity off its parent-directory sibling
      $autoload = "{$this->path}vendor/autoload.php";
      if (is_file($autoload) === true) {
         require_once $autoload;
      }
   }

   /**
    * Boot the project: mount its environment, then run its entry closure.
    *
    * Defines BOOTGLY_PROJECT constant and registers in Projects on first call.
    * A second boot in the same process throws a fatal Error.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    */
   public function boot (array $arguments = [], array $options = []): void
   {
      // @ Environment (constant, provenance, configs, catalogs, autoload)
      $this->mount();

      // ! Registration is complete — the project IS booted before its closure
      //   runs, because server closures never return (their loops exit the
      //   process) and the events must not depend on that return
      $this->booted = true;

      // @ Events — project booted (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Boot) && $Emitter->emit(Events::Boot, $this);

      // @
      ($this->boot)($arguments, $options);
   }

   /**
    * Emit `Project.Shutdown` when a booted project is destroyed.
    *
    * `__destruct` timing is GC-bound; for the booted project (held by the
    * BOOTGLY_PROJECT constant + the Projects registry) this lands at process
    * teardown. A constructed-but-never-booted project never emits, and
    * neither does a process marked `detached` — its exit is a handoff.
    */
   public function __destruct ()
   {
      // ?
      if ($this->booted === false || $this->detached === true) {
         return;
      }

      // @ Events — project shutting down (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Shutdown) && $Emitter->emit(Events::Shutdown, $this);
   }
}

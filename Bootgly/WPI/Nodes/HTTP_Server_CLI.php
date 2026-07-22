<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const LOCK_SH;
use const LOCK_UN;
use const SIG_DFL;
use const SIGALRM;
use const SIGCHLD;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGKILL;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGURG;
use const SIGUSR1;
use const SIGUSR2;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use const WNOHANG;
use function array_diff;
use function array_reverse;
use function array_values;
use function clearstatcache;
use function cli_set_process_title;
use function count;
use function dirname;
use function explode;
use function fclose;
use function feof;
use function file_get_contents;
use function flock;
use function fopen;
use function fread;
use function fstat;
use function function_exists;
use function fwrite;
use function glob;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function lchgrp;
use function lchown;
use function lstat;
use function max;
use function microtime;
use function mkdir;
use function opcache_invalidate;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_waitpid;
use function posix_geteuid;
use function posix_getgrnam;
use function posix_getpid;
use function posix_getppid;
use function posix_getpwnam;
use function posix_getuid;
use function posix_kill;
use function preg_match;
use function rtrim;
use function scandir;
use function spl_object_id;
use function sprintf;
use function stat;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_context_create;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function time;
use function trim;
use function usleep;
use BackedEnum;
use Closure;
use Exception;
use Generator;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Throwable;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Event;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Events as TCP_Client_Events;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


class HTTP_Server_CLI extends TCP_Server_CLI implements HTTP, Server
{
   // * Config
   // ...inherited from TCP_Server_CLI

   // * Data
   // ...inherited from TCP_Server_CLI

   // * Metadata
   // ...inherited from TCP_Server_CLI
   // # Socket
   protected string $process = 'Bootgly_HTTP_Server';
   // # HTTP/2
   /**
    * @var bool Whether HTTP/2 is served at all — gates both the TLS-ALPN
    * advertisement and the cleartext prior-knowledge preface probe.
    * Driven by `configure(enableHTTP2:)`; `false` makes the server
    * HTTP/1.x-only.
    */
   public static bool $enableHTTP2 = true;
   // # Health
   /**
    * @var null|string Built-in health-check endpoint path (K8s
    * liveness/readiness probes). Driven by `configure(health:)`; when set
    * (e.g. `'/health'`), GET/HEAD requests to this exact path are answered
    * by `Encoders\Check` BEFORE the middleware pipeline — user middlewares
    * can never break a probe. `null` (default) disables it.
    */
   public static null|string $health = null;
   // # Auto-TLS
   /**
    * Auto-TLS (ACME) configuration — set by
    * `configure(secure: new AutoTLS(...))`; null keeps Auto-TLS off.
    */
   public protected(set) null|AutoTLS $AutoTLS = null;
   /**
    * @var resource|null HTTP-01 gate socket — bound on the validation port
    * by the master pre-demote; inherited by the helper child.
    */
   protected $Gate = null;
   /** Port-80 helper child PID (0 = none). */
   public protected(set) int $helper = 0;
   public protected(set) bool $helperReady = false;
   /** Remote master whose helper serves this instance's exact spool. */
   protected int $validator = 0;
   /** Certifier (issuance) child PID (0 = none). */
   public protected(set) int $certifier = 0;
   /** Last renewal-need check (unix ts). */
   protected int $checked = 0;
   /** Last helper watchdog + manifest watch (unix ts). */
   protected int $watched = 0;
   /** Certificate path currently applied on the sockets (manifest watch). */
   protected string $applied = '';
   /** Generation acknowledged by every current worker. */
   protected string $appliedGeneration = '';
   /** Request attempt acknowledged by this process for the active generation. */
   protected string $appliedAttempt = '';
   /** Generation currently waiting for worker convergence. */
   protected null|CertificateSnapshot $PendingSnapshot = null;
   /** Unique rendezvous attempt currently waiting for worker convergence. */
   protected string $pendingAttempt = '';
   protected float $pendingSince = 0.0;
   protected int $pendingAttempts = 0;
   protected bool $swapFallbackQueued = false;
   public static int $swapAckTimeout = 3;
   public static int $swapAckRetries = 2;
   /**
    * Challenge path chartered by this instance and its process-unique owner ID.
    */
   protected null|string $chartered = null;
   protected string $challengeOwner = '';

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (Modes $Mode = Modes::Daemon)
   {
      // * Config
      // ...inherited from TCP_Server_CLI

      // * Data
      // ...inherited from TCP_Server_CLI

      // * Metadata
      // ...inherited from TCP_Server_CLI


      // \
      parent::__construct($Mode);
      $this->challengeOwner = static::class . '#' . spl_object_id($this);
      // * Config
      $this->socket = $this->secure !== null
         ? 'https'
         : 'http';
      // @ Configure Logger
      $this->Logger = new Logger(channel: 'HTTP.Server.CLI');

      // . Request, Response, Router
      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router;

      // . Decoders, Encoders
      self::$Decoder = new Decoder_;

      switch ($Mode) {
         case Modes::Test:
            self::$Encoder = new Encoder_Testing;
            break;
         default:
            self::$Encoder = new Encoder_;
      }

      $WPI = WPI;
      // # HTTP
      $WPI->Server = $this;
      $WPI->Response = &self::$Response;
      $WPI->Request = &self::$Request;
      $WPI->Router = &self::$Router;
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Auto-TLS
         // @ Hot certificate swap — generation-record dispatch: the certifier
         //   wakes the master, the master applies + relays to every worker,
         //   and each worker swaps its own listening socket.
         case '@swap':
            $this->exchange();
            return true;
      }

      return parent::__get($name);
   }

   /**
    * A swap request is a dedicated generation record, not a command-file
    * payload. SIGURG is only the wake-up edge; coalescing is harmless because
    * every process reads and applies the latest desired generation.
    */
   public function handle (int $signal): void
   {
      if (
         $signal === SIGURG
         && $this->AutoTLS !== null
         && ($desired = $this->AutoTLS->Swaps->fetch()) !== null
         && (
            $desired['generation'] !== $this->appliedGeneration
            || $desired['attempt'] !== $this->appliedAttempt
         )
      ) {
         $this->exchange();
      }

      parent::handle($signal);
   }

   /**
    * Configure the HTTP Server.
    *
    * @param null|array<string,Closure(object):Response\Resource> $responseResources Lazy response resource factories.
    *
    * @return self The HTTP Server instance, for chaining 
    */
   public function configure (
      string $host, int $port, int $workers,
      null|array|AutoTLS $secure = null,
      null|string $user = null, null|string $group = null,
      null|bool $enableHTTP2 = null,
      null|int $requestMaxFileSize = null, null|int $requestMaxBodySize = null,
      null|int $requestMaxMultipartFieldSize = null,
      null|int $requestMaxMultipartHeaderSize = null,
      null|int $requestMaxMultipartFields = null,
      null|int $requestMaxMultipartFiles = null,
      null|int $downloadsMaxBytesOnDisk = null,
      null|int $maxConnections = null,
      null|int $maxConnectionsPerIP = null,
      null|array $responseResources = null,
      null|string $health = null
   ): self
   {
      // ? configure() is a PRE-START contract. Only the initial Booting and
      //   repeat Configuring states are legal: Starting has already crossed
      //   the worker-fork boundary, while Paused/Stopping are equally live.
      if (in_array($this->Status, [Status::Booting, Status::Configuring], true) === false) {
         $this->Logger->log(
            error: '@\\;configure() rejected: the server already crossed its pre-start boundary — use reload() to reconfigure.@\\;'
         );

         return $this;
      }

      // @ Auto-TLS — resolve the typed config into a plain SSL context array.
      //   Reconfiguration tears the previous Auto-TLS runtime down (helper,
      //   certifier, gate, chartered challenge path — see halt()), but only
      //   AFTER the new configuration proved servable.
      if ($secure instanceof AutoTLS) {
         if (posix_getuid() === 0 && $user === null && $this->Mode !== Modes::Test) {
            throw new RuntimeException(
               'Auto-TLS started as root requires the server `user` option before credential storage is accessed.'
            );
         }
         if (posix_getuid() === 0 && $user !== null && posix_getpwnam($user) === false) {
            throw new RuntimeException(
               "Auto-TLS runtime user `{$user}` does not exist."
            );
         }
         if (posix_getuid() === 0 && $group !== null && posix_getgrnam($group) === false) {
            throw new RuntimeException(
               "Auto-TLS runtime group `{$group}` does not exist."
            );
         }

         // ? TLS on the HTTP-01 validation port is a contradiction
         if ($port === $secure->port) {
            $this->Logger->log(
               error: "@\\;Auto-TLS: the server port ({$port}) cannot be the HTTP-01 validation port — the CA validates over plain HTTP.@\\;"
            );
            exit(1);
         }

         // ! The NEW configuration must prove servable BEFORE the previous
         //   runtime is torn down — a throwing forge() leaves the old
         //   Auto-TLS untouched (rollback-safe transition)
         $secure->forge();
         $Snapshot = $secure->snapshot();
         if ($Snapshot === null) {
            throw new RuntimeException(
               'Auto-TLS did not produce a fully validated startup credential.'
            );
         }
         if ($secure->Swaps->request($Snapshot) === null) {
            throw new RuntimeException(
               'Auto-TLS could not persist its initial generation rendezvous.'
            );
         }

         if ($this->AutoTLS !== null) {
            $this->halt();
            $this->watched = 0;
            $this->checked = 0;
         }

         $this->AutoTLS = $secure;

         // ! Enable responders under an instance-owned lease. Other server
         //   objects keep their own paths; responders search all charters.
         $this->chartered = Challenges::charter(
            $this->challengeOwner,
            $secure->challenges
         );

         // ? Publication is not activation. The startup readiness barrier
         //   advances these only after every forked worker has bound, sealed,
         //   probed and durably acknowledged this exact request attempt.
         $this->applied = '';
         $this->appliedGeneration = '';
         $this->appliedAttempt = '';
         $this->PendingSnapshot = null;
         $this->pendingAttempt = '';
         $this->swapFallbackQueued = false;
         $secure = $secure->options + $Snapshot->secure();
      }
      else {
         // ! Reconfiguration is never sticky: a manual array or null fully
         //   leaves Auto-TLS management — halt() also releases the challenge
         //   hook this instance chartered. Sibling leases remain intact.
         if ($this->AutoTLS !== null) {
            $this->halt();
            $this->watched = 0;
            $this->checked = 0;
         }

         $this->AutoTLS = null;
         $this->applied = '';
         $this->appliedGeneration = '';
         $this->appliedAttempt = '';
         $this->PendingSnapshot = null;
         $this->pendingAttempt = '';
      }
      /** @var null|array<string,mixed> $secure */

      // ? Safe server defaults: PHP's SSL context inherits verify_peer=true,
      //   which makes OpenSSL send a CertificateRequest — browsers then prompt
      //   for a CLIENT certificate (accidental mTLS). A public server must not
      //   request one unless explicitly configured; user options keep
      //   precedence, so real mTLS stays one override away.
      if ($secure !== null) {
         $secure += [
            'verify_peer'      => false,
            'verify_peer_name' => false,
         ];
      }

      // @ HTTP/2 — on by default; `enableHTTP2: false` disables BOTH the
      //   TLS-ALPN advertisement (RFC 9113 §3.2) and the cleartext
      //   prior-knowledge preface probe (§3.3), making the server
      //   HTTP/1.x-only.
      self::$enableHTTP2 = ($enableHTTP2 !== false);

      // @ Health endpoint — opt-in; unconditional assign so statics never
      //   inherit a previous configuration in the same process
      self::$health = $health;

      if ($secure !== null && self::$enableHTTP2) {
         $secure['alpn_protocols'] ??= 'h2,http/1.1';

         self::$Protocols['h2'] = static function (Connection $Connection): void {
            $Decoder = new Decoder_HTTP2;
            $Connection->Decoder = $Decoder;
            $Connection->decoded = $Decoder;
         };
      }
      else {
         // ? Statics survive re-configuration in the same process — never
         //   inherit a previous instance's installer.
         unset(self::$Protocols['h2']);
      }

      parent::configure($host, $port, $workers, $secure, $user, $group);

      if ($host === '0.0.0.0') {
         $this->domain ??= 'localhost';
      }

      // * Config
      $this->socket = $this->secure !== null
         ? 'https://'
         : 'http://';

      // @ Request limits
      if ($requestMaxFileSize !== null) {
         Request::$maxFileSize = $requestMaxFileSize;
      }
      if ($requestMaxBodySize !== null) {
         Request::$maxBodySize = $requestMaxBodySize;
      }
      if ($requestMaxMultipartFieldSize !== null) {
         Request::$maxMultipartFieldSize = $requestMaxMultipartFieldSize;
      }
      if ($requestMaxMultipartHeaderSize !== null) {
         Request::$maxMultipartHeaderSize = $requestMaxMultipartHeaderSize;
      }
      if ($requestMaxMultipartFields !== null) {
         Request::$maxMultipartFields = $requestMaxMultipartFields;
      }
      if ($requestMaxMultipartFiles !== null) {
         Request::$maxMultipartFiles = $requestMaxMultipartFiles;
      }
      if ($downloadsMaxBytesOnDisk !== null) {
         Downloads::$maxBytesOnDisk = $downloadsMaxBytesOnDisk;
      }
      // @ Connection-exhaustion caps (audit F-2)
      if ($maxConnections !== null) {
         self::$maxConnections = $maxConnections;
      }
      if ($maxConnectionsPerIP !== null) {
         self::$maxConnectionsPerIP = $maxConnectionsPerIP;
      }
      if ($responseResources !== null) {
         self::$Response->Resources->load($responseResources);
      }

      return $this;
   }

   /**
    * Register an event handler for the HTTP Server.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self The HTTP Server instance, for chaining.
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof Events === false) {
         throw new InvalidArgumentException('Invalid HTTP Server event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }
      $this->Events[$Event->value] = true;

      match ($Event) {
         Events::RequestReceived => $this->listen($Callback),
         Events::ServerAdvertised => $this->onServerAdvertised = $Callback,
         Events::ServerStarted => $this->onServerStarted = $Callback,
         Events::ServerStopped => $this->onServerStopped = $Callback,
      };

      // :
      return $this;
   }

   private function listen (Closure $Callback): void
   {
      if (isset(SAPI::$Middlewares) === false) {
         SAPI::$Middlewares = new Middlewares;
      }

      SAPI::$Handler = $Callback;
   }

   /**
    * Boot the Server API (honoring `Modes::Test`), or bail when no request
    * handler is wired. Overrides the base for the HTTP-specific error message.
    *
    * ! Honor server Mode: under `Modes::Test` the constructor installs
    *   `Encoder_Testing`; booting `Production` here would flip it back to
    *   `Encoder_` right before fork, leaving workers racing SIGUSR1
    *   (`@test init`) against the first request and serving 503 from `Encoder_`
    *   before the handler is installed.
    */
   protected function loading (): void
   {
      if (self::$Application) {
         self::$Application::boot(
            $this->Mode === Modes::Test
               ? Environments::Test
               : Environments::Production
         );
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->Logger->log(error: '@\;No request handler defined. Call on(Events::RequestReceived, ...) before start().@\;');
         exit(1);
      }
   }

   /**
    * Pre-fork setup: initialize the stable cross-worker upload controller
    * inode, then purge temp files orphaned by a previous crashed run.
    */
   protected function booting (): void
   {
      // @ Keep the controller inode under the protected per-service process
      //   state namespace. Workers and a re-executed demoted master reopen
      //   independent descriptors without gaining pathname replacement rights.
      if (Downloads::init(
         path: $this->Process->State->pidLockFile . '.downloads',
         user: $this->user,
         group: $this->group,
      ) === false) {
         // ! Safe degraded mode: keep non-upload routes available, but every
         //   positive multipart file reservation fails closed in reserve().
         //   Never silently advertise an aggregate ceiling that is disabled.
         $this->Logger->log(
            critical: '@\;Aggregate download disk controller unavailable: multipart file writes will fail closed until the server is restarted with a valid counter inode and advisory locking.@.;'
         );
      }
      // @ Purge temp files orphaned by a previous (crashed) run before the
      //   first fork — no worker is in-flight yet, so a full sweep is safe
      //   (audit F-10). The shared counter is reset to 0 by init().
      Downloads::sweep();

      // @ Auto-TLS — own the storage tree, bind the HTTP-01 gate and fork
      //   the port-80 helper. Master-side, pre-fork and PRE-DEMOTE: binding
      //   a port < 1024 is impossible after privileges are dropped.
      if ($this->AutoTLS !== null) {
         $this->prime();
      }
   }

   /** @return array<string,resource> */
   protected function export (): array
   {
      return is_resource($this->Gate)
         ? ['http01.gate' => $this->Gate]
         : [];
   }

   /** @param array<string,resource> $Resources */
   protected function import (array $Resources): bool
   {
      if ($Resources === []) {
         return true;
      }
      $Gate = $Resources['http01.gate'] ?? null;
      if (
         count($Resources) !== 1
         || $this->AutoTLS === null
         || $this->validate($Gate, $this->AutoTLS->port) === false
      ) {
         return false;
      }
      /** @var resource $Gate */
      $this->Gate = $Gate;

      return true;
   }

   /**
    * Do not advertise startup until every current worker has bound its socket,
    * sealed/probed the startup credential and persisted an ACK for the exact
    * published attempt. ACKs can arrive before the master enters this barrier;
    * they remain valid because the attempt was published before the fork.
    */
   protected function ready (): bool
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return true;
      }

      if ($this->exchange() === false) {
         $this->fault = $this->diagnose($this->Process->Children->PIDs);
         $this->halt();
         return false;
      }

      // ! A worker that dies inside the barrier can never acknowledge — its
      //   startup credential was rejected, or it crashed. The barrier reaps
      //   without reforking (`$starting`), so a lost worker is a definitive
      //   failure: fail fast instead of waiting out the whole ACK budget.
      $Workers = $this->Process->Children->PIDs;

      $deadline = microtime(true)
         + ((self::$swapAckRetries + 1) * max(1, self::$swapAckTimeout))
         + 1.0;
      while ($this->PendingSnapshot !== null && microtime(true) < $deadline) {
         pcntl_signal_dispatch();
         if ($this->Process->Children->PIDs !== $Workers) {
            break;
         }
         $this->reconcile();
         if ($this->PendingSnapshot === null) {
            break;
         }
         usleep(10000);
      }

      $desired = $AutoTLS->Swaps->fetch();
      $ready = $desired !== null
         && $this->Process->Children->PIDs === $Workers
         && $this->PendingSnapshot === null
         && $this->swapFallbackQueued === false
         && $this->appliedGeneration === $desired['generation']
         && $this->appliedAttempt === $desired['attempt'];
      if ($ready === false) {
         $this->fault = $this->diagnose($Workers);
         $this->halt();
      }

      return $ready;
   }

   /**
    * Compose the reason the Auto-TLS startup barrier failed — the demoted
    * master probes the credential store it shares with the demoted workers
    * (an EACCES here IS their error), then the workers' own negative
    * acknowledgements, then the barrier bookkeeping itself.
    *
    * @param array<int,int> $Workers Worker PIDs when the barrier opened.
    */
   private function diagnose (array $Workers): string
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return '';
      }

      // # Store reachability, segment by segment — a root-owned 0700
      //   ancestor blocks traversal into an otherwise owned tree
      $walk = '';
      $previous = null;
      foreach (explode('/', trim($AutoTLS->path, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }

         $walk .= "/{$segment}";
         $status = @stat($walk);
         if ($status === false) {
            $uid = posix_geteuid();
            $detail = 'it does not exist or cannot be read';
            if (is_array($previous)) {
               $mode = $previous['mode'] & 0777;
               $owner = $previous['uid'];
               if ($owner !== $uid || ($mode & 0100) === 0) {
                  $octal = sprintf('%03o', $mode);
                  $detail = "its parent is mode {$octal} owned by uid {$owner} — not traversable by uid {$uid}";
               }
            }

            return "Auto-TLS credential store unreachable at `{$walk}` (uid {$uid}): {$detail}.";
         }
         $previous = $status;
      }

      // # Failed worker acknowledgements carry their own reason
      $desired = $AutoTLS->Swaps->fetch();
      $generation = $desired['generation'] ?? null;
      $attempt = $desired['attempt'] ?? null;
      if (is_string($generation) && is_string($attempt)) {
         foreach ($AutoTLS->Swaps->collect($generation, $attempt) as $ack) {
            if ($ack['success'] === false && $ack['error'] !== '') {
               return "Auto-TLS worker {$ack['pid']} rejected the startup credential: {$ack['error']}";
            }
         }
      }

      // # Workers lost inside the barrier acknowledged nothing
      $lost = array_values(array_diff($Workers, $this->Process->Children->PIDs));
      if ($lost !== []) {
         $list = implode(', ', $lost);

         return "Auto-TLS worker(s) {$list} exited inside the startup barrier before acknowledging the credential.";
      }

      // :
      return 'Auto-TLS: the workers did not acknowledge the startup credential within the barrier budget.';
   }

   /**
    * Master supervision tick — the Auto-TLS pump (helper watchdog, manifest
    * watch and the renewal-need check that forks the certifier child).
    */
   protected function tick (): void
   {
      parent::tick();

      if ($this->AutoTLS === null) {
         return;
      }

      $this->supervise();
   }

   /** @return array<string,mixed> Persisted HTTP and Auto-TLS topology. */
   protected function describe (): array
   {
      $state = parent::describe();
      if ($this->AutoTLS !== null) {
         $state['AutoTLS'] = [
            'validation' => $this->AutoTLS->port,
            'challenges' => $this->AutoTLS->challenges,
            // Only a locally owned, acknowledged helper is advertised. A
            // delegated server may consume a lease but never re-export it.
            'helper' => is_resource($this->Gate) ? $this->helper : 0,
            'ready' => is_resource($this->Gate) && $this->helperReady
         ];
      }

      return $state;
   }

   /**
    * Per-worker wiring: drop the inherited HTTP-01 gate descriptor — it
    * belongs to the master (rebind on helper respawn) and the helper child.
    */
   protected function wire (int $index): void
   {
      if (is_resource($this->Gate)) {
         fclose($this->Gate);
      }
      $this->Gate = null;

      // A replacement worker joins the current generation before accepting
      // traffic and emits the acknowledgement the master is waiting for.
      if (
         $this->AutoTLS !== null
         && $this->AutoTLS->Swaps->fetch() !== null
         && $this->exchange() === false
      ) {
         $this->Logger->log(
            critical: '@\\;Auto-TLS: worker startup credential activation/acknowledgement failed; refusing to accept traffic.@\\;'
         );
         exit(1);
      }
   }

   public function stop (): void
   {
      // @ Tear down cross-worker upload counter (master only). The
      //   `ServerStopped` hook itself is fired by the base `stop()`.
      if (isset($this->Process) && $this->Process->level === 'master') {
         Downloads::destroy();

         $this->halt();
      }

      parent::stop();
   }

   /**
    * Reload (SIGUSR2 re-exec): terminate the Auto-TLS auxiliary children but
    * retain the bound gate for the base class' same-UID descriptor handoff.
    * The fresh image adopts it and forks a newly-managed helper without ever
    * regaining root or leaving an orphan responder behind.
    */
   protected function reload (): void
   {
      // ? Mirror the base feasibility guards BEFORE tearing anything down:
      //   an aborted reload must not leave the gate closed and the helper
      //   gone with nothing for the watchdog to refork
      if ($this->Process->level !== 'master') {
         return;
      }
      $entry = self::$argv[0] ?? '';
      if ($entry === '' || is_file($entry) === false) {
         parent::reload(); // surfaces the abort log; tears nothing down

         return;
      }

      // Keep the already-bound gate descriptor for the same-UID SCM_RIGHTS
      // relay; only its helper/certifier children are torn down here.
      $this->halt(preserveGate: true);

      parent::reload();

      // parent::reload() returns only when feasibility/handoff failed before
      // worker drain. Restore HTTP-01 availability on the still-running image.
      if ($this->AutoTLS !== null) {
         $this->chartered = Challenges::charter(
            $this->challengeOwner,
            $this->AutoTLS->challenges
         );
         if (is_resource($this->Gate) && $this->helper === 0) {
            $this->guard();
         }
      }
   }

   /**
    * Auto-TLS teardown: the helper/certifier children never orphan and the
    * gate descriptor never leaks. Kills are parentage-checked — a recycled
    * PID belonging to an unrelated process is never signaled — and the
    * signaled children are reaped (bounded) so no zombie survives a
    * stop/reload/reconfigure.
    */
   private function halt (bool $preserveGate = false): void
   {
      $terminated = [];

      if ($this->helper > 0) {
         if ($this->alive($this->helper)) {
            posix_kill($this->helper, SIGTERM);
            $terminated[] = $this->helper;
         }
         else {
            pcntl_waitpid($this->helper, $status, WNOHANG);
         }
         $this->helper = 0;
         $this->helperReady = false;
      }
      $this->helperReady = false;
      $this->validator = 0;
      if ($this->certifier > 0) {
         if ($this->alive($this->certifier)) {
            posix_kill($this->certifier, SIGTERM);
            $terminated[] = $this->certifier;
         }
         else {
            pcntl_waitpid($this->certifier, $status, WNOHANG);
         }
         $this->certifier = 0;
      }

      // @ Reap the signaled children (bounded ~2s), then ESCALATE: a child
      //   still alive past the budget is SIGKILLed and reaped — a live
      //   auxiliary is never silently forgotten while this master survives
      $deadline = microtime(true) + 2.0;
      foreach ($terminated as $PID) {
         $reaped = 0;
         while (microtime(true) < $deadline) {
            $reaped = pcntl_waitpid($PID, $status, WNOHANG);
            if ($reaped === $PID || $reaped === -1) {
               break;
            }
            usleep(50000);
         }

         if ($reaped !== $PID && $this->alive($PID)) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
      }

      if ($preserveGate === false && is_resource($this->Gate)) {
         fclose($this->Gate);
         $this->Gate = null;
      }

      // @ Release only this server's lease; sibling responders stay active.
      Challenges::release($this->challengeOwner);
      $this->chartered = null;
   }

   /**
    * Auto-TLS boot (master, pre-fork, pre-demote): chown the certificate
    * storage tree to the target user (the demoted certifier writes it),
    * bind the HTTP-01 gate socket and fork the persistent port-80 helper.
    */
   private function prime (): void
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return;
      }

      // ! Binding privileged ports as root without a configured demotion
      //   identity would run application workers and writable Auto-TLS state
      //   as root. Refuse that production footgun explicitly.
      if (posix_getuid() === 0 && $this->user === null && $this->Mode !== Modes::Test) {
         throw new RuntimeException(
            'Auto-TLS started as root requires the server `user` option so workers, helper and certifier are demoted after privileged binds.'
         );
      }

      // @ Hand the storage tree to the demoted runtime user
      if ($this->own($AutoTLS->path) === false) {
         throw new RuntimeException(
            "Auto-TLS storage tree `{$AutoTLS->path}` could not be handed to the runtime identity safely."
         );
      }
      if (str_starts_with($AutoTLS->challenges, $AutoTLS->path) === false) {
         if (is_dir($AutoTLS->challenges) === false && mkdir($AutoTLS->challenges, 0700, true) === false) {
            throw new RuntimeException(
               "Auto-TLS challenge directory `{$AutoTLS->challenges}` could not be created."
            );
         }
         if ($this->own($AutoTLS->challenges) === false) {
            throw new RuntimeException(
               "Auto-TLS challenge tree `{$AutoTLS->challenges}` could not be handed to the runtime identity safely."
            );
         }
      }

      $this->bind();
      if (is_resource($this->Gate) && $this->helper === 0) {
         $this->guard();
      }
   }

   /**
    * Bind the validation gate, or consume a proven same-spool helper lease.
    */
   private function bind (): void
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null || is_resource($this->Gate)) {
         return;
      }

      if ($this->delegate($AutoTLS)) {
         return;
      }

      // @ Bind the gate WITHOUT so_reuseport — deliberately exclusive: with
      //   it the kernel would balance real user traffic between this
      //   token-only socket and any later-started :80 server, black-holing
      //   half its connections; an exclusive bind fails loudly instead.
      $error_code = 0;
      $error_message = '';
      $bindHost = str_contains((string) $this->host, ':')
         ? "[{$this->host}]"
         : $this->host;
      $Gate = @stream_socket_server(
         "tcp://{$bindHost}:{$AutoTLS->port}",
         $error_code,
         $error_message
      );
      if ($Gate === false) {
         $this->helperReady = false;
         $this->Logger->log(
            error: "@\\;Auto-TLS: could not prove or bind an HTTP-01 responder on port {$AutoTLS->port} ({$error_message}); renewal is paused until a same-spool helper or the port becomes available.@\\;"
         );
         return;
      }

      $this->Gate = $Gate;
      $this->validator = 0;

      // @ The final daemon master already exists (TCP detach happens before
      //   booting()), so every mode can fork an owned helper here.
      $this->guard();
   }

   /**
    * Consume another Bootgly master's readiness lease only when its live
    * helper serves this exact validation port and challenge directory.
    */
   private function delegate (AutoTLS $AutoTLS): bool
   {
      $previous = $this->validator;
      $this->validator = 0;
      $this->helperReady = false;

      foreach (glob(BOOTGLY_STORAGE_DIR . 'pids/*.json') ?: [] as $state) {
         if (is_link($state) || $state === $this->Process->State->pidFile) {
            continue;
         }
         $decoded = $this->decode($state);
         $master = is_array($decoded) ? ($decoded['master'] ?? null) : null;
         $lease = is_array($decoded) ? ($decoded['AutoTLS'] ?? null) : null;
         $helper = is_array($lease) ? ($lease['helper'] ?? null) : null;
         if (
            is_int($master) === false || $master < 2
            || is_array($lease) === false
            || ($lease['validation'] ?? null) !== $AutoTLS->port
            || ($lease['challenges'] ?? null) !== $AutoTLS->challenges
            || ($lease['ready'] ?? null) !== true
            || is_int($helper) === false || $helper < 2
            || posix_kill($master, 0) === false
            || posix_kill($helper, 0) === false
         ) {
            continue;
         }

         $status = file_get_contents("/proc/{$helper}/status");
         if (
            is_string($status) === false
            || preg_match('/^State:\s+Z/m', $status) === 1
            || preg_match('/^PPid:\t(\d+)$/m', $status, $matches) !== 1
            || (int) $matches[1] !== $master
         ) {
            continue;
         }

         $this->validator = $master;
         $this->helperReady = true;
         if ($previous !== $master) {
            $this->Logger->log(
               info: "@\\;Auto-TLS: sharing the ready HTTP-01 helper owned by master {$master}; validation port {$AutoTLS->port} and spool `{$AutoTLS->challenges}` match exactly.@\\;"
            );
         }

         return true;
      }

      return false;
   }

   /** @return null|array<string,mixed> Read one locked process-state object. */
   private function decode (string $file): null|array
   {
      if (is_link($file) || is_file($file) === false) {
         return null;
      }
      $before = @lstat($file);
      $Handle = @fopen($file, 'rb');
      if ($Handle === false || flock($Handle, LOCK_SH) === false) {
         is_resource($Handle) && fclose($Handle);
         return null;
      }

      try {
         $opened = fstat($Handle);
         $after = @lstat($file);
         if (
            is_array($before) === false || is_array($opened) === false || is_array($after) === false
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || $after['dev'] !== $opened['dev']
            || $after['ino'] !== $opened['ino']
         ) {
            return null;
         }
         $JSON = stream_get_contents($Handle, 65537);
      }
      finally {
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }
      if (is_string($JSON) === false || strlen($JSON) > 65536) {
         return null;
      }
      $decoded = json_decode($JSON, true);
      if (is_array($decoded) === false) {
         return null;
      }
      foreach ($decoded as $key => $_) {
         if (is_string($key) === false) {
            return null;
         }
      }
      /** @var array<string,mixed> $decoded */

      return $decoded;
   }

   /**
    * Fork the port-80 helper child on the already-bound gate. Never joins
    * `Process->Children` — `recover()` must not refork it as a TLS worker.
    */
   private function guard (): void
   {
      if (is_resource($this->Gate) === false) {
         return;
      }

      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         $this->Logger->log(error: '@\\;Auto-TLS: could not create the helper readiness channel.@\\;');
         return;
      }

      $master = posix_getpid();
      $PID = pcntl_fork();

      if ($PID < 0) {
         fclose($Pair[0]);
         fclose($Pair[1]);
         $this->Logger->log(error: '@\\;Auto-TLS: failed to fork the HTTP-01 helper.@\\;');
         return;
      }

      // ?: Master
      if ($PID > 0) {
         $this->helper = $PID;
         $this->helperReady = false;
         fclose($Pair[1]);

         stream_set_blocking($Pair[0], false);
         $ready = '';
         $deadline = microtime(true) + 2.0;
         while (strlen($ready) < 5 && microtime(true) < $deadline) {
            $remaining = max(0.0, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $read = [$Pair[0]];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected !== 1) {
               break;
            }
            $chunk = fread($Pair[0], 5 - strlen($ready));
            if ($chunk === false || ($chunk === '' && feof($Pair[0]))) {
               break;
            }
            $ready .= $chunk;
         }
         fclose($Pair[0]);
         if ($ready === 'ready') {
            $this->helperReady = true;
            return;
         }

         if ($this->alive($PID)) {
            posix_kill($PID, SIGKILL);
         }
         pcntl_waitpid($PID, $status);
         $this->helper = 0;
         $this->Logger->log(error: '@\\;Auto-TLS: HTTP-01 helper did not acknowledge readiness.@\\;');
         return;
      }

      // # Helper child
      fclose($Pair[0]);
      $this->attend($Pair[1], $master);
   }

   /**
    * Helper child body: a minimal blocking HTTP/1.0 responder on the gate —
    * ACME tokens answered from the shared dir, everything else redirected
   * to HTTPS (308). Runs demoted; exits with the default signal behavior.
    *
    * @param resource $Ready
    */
   private function attend ($Ready, int $master): never
   {
      cli_set_process_title("{$this->process}: ACME helper");
      $this->Process->State->detach();

      // The helper must not keep the daemon launcher's master-only readiness
      // descriptor alive if the master fails during the rest of startup.
      if (is_resource($this->daemonReady)) {
         fclose($this->daemonReady);
         $this->daemonReady = null;
      }

      // ! The master's signal handlers must not run here
      foreach ([
         SIGALRM, SIGUSR1, SIGURG, SIGHUP, SIGINT, SIGQUIT, SIGTERM,
         SIGTSTP, SIGCONT, SIGUSR2, SIGCHLD, SIGIOT, SIGIO
      ] as $signal) {
         pcntl_signal($signal, SIG_DFL);
      }

      // The helper owns only the HTTP-01 gate. On a re-exec boot it also
      // inherits the transferred HTTPS listener pool; retaining those copies
      // would keep the service port bound after its master/workers stop.
      foreach ($this->Listeners as $Listener) {
         fclose($Listener);
      }
      $this->Listeners = [];

      $this->demote();

      /** @var resource $Gate */
      $Gate = $this->Gate;
      stream_set_blocking($Gate, false);
      $offset = 0;
      while ($offset < 5) {
         $written = fwrite($Ready, substr('ready', $offset));
         if ($written === false || $written === 0) {
            fclose($Ready);
            exit(1);
         }
         $offset += $written;
      }
      fclose($Ready);

      /** @var array<int,array{socket:resource,head:string,response:string,offset:int,deadline:float}> $clients */
      $clients = [];
      while (true) {
         // ? Orphaned (master killed without stop()) — leave with it
         if (posix_getppid() !== $master) {
            exit(0);
         }

         $read = [$Gate];
         $write = [];
         foreach ($clients as $client) {
            if ($client['response'] === '') {
               $read[] = $client['socket'];
            }
            else {
               $write[] = $client['socket'];
            }
         }
         $except = null;
         @stream_select($read, $write, $except, 1, 0);

         // @ Accept every queued connection without blocking. A fixed cap
         //   bounds descriptors/memory under a validation-port flood.
         foreach ($read as $index => $Readable) {
            if ($Readable !== $Gate) {
               continue;
            }
            unset($read[$index]);
            while (($Socket = @stream_socket_accept($Gate, 0)) !== false) {
               stream_set_blocking($Socket, false);
               if (count($clients) >= 128) {
                  fclose($Socket);
                  continue;
               }
               $clients[(int) $Socket] = [
                  'socket' => $Socket,
                  'head' => '',
                  'response' => '',
                  'offset' => 0,
                  'deadline' => microtime(true) + 5.0
               ];
            }
         }

         // @ Incremental bounded reads — slow clients coexist; none owns the
         //   helper loop while waiting for its next byte.
         foreach ($read as $Readable) {
            $ID = (int) $Readable;
            if (isset($clients[$ID]) === false) {
               continue;
            }
            $chunk = @fread($Readable, 2048);
            if ($chunk !== false && $chunk !== '') {
               $clients[$ID]['head'] .= $chunk;
            }
            $head = $clients[$ID]['head'];
            if (
               strpos($head, "\r\n\r\n") !== false
               || strlen($head) >= 8192
               || feof($Readable)
            ) {
               $clients[$ID]['response'] = $this->answer($head);
               $clients[$ID]['deadline'] = microtime(true) + 2.0;
            }
         }

         // @ Nonblocking writes with a separate short deadline.
         foreach ($write as $Writable) {
            $ID = (int) $Writable;
            $client = $clients[$ID] ?? null;
            if ($client === null) {
               continue;
            }
            $remaining = substr($client['response'], $client['offset']);
            $written = @fwrite($Writable, $remaining);
            if ($written !== false && $written > 0) {
               $clients[$ID]['offset'] += $written;
            }
            if ($clients[$ID]['offset'] >= strlen($client['response'])) {
               fclose($Writable);
               unset($clients[$ID]);
            }
         }

         $now = microtime(true);
         foreach ($clients as $ID => $client) {
            if ($now >= $client['deadline']) {
               fclose($client['socket']);
               unset($clients[$ID]);
            }
         }
      }
   }

   /**
    * Write one helper response: 200 with the key authorization for a known
    * ACME token, 404 for an unknown one, 308 to HTTPS for everything else.
    *
    */
   private function answer (string $head): string
   {
      // ? Request line
      if (preg_match('/^(GET|HEAD) (\S+) HTTP/', $head, $matches) !== 1) {
         return "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
      }
      $method = $matches[1];
      $URI = $matches[2];

      // ?: ACME HTTP-01 token
      if (strncmp($URI, '/.well-known/acme-challenge/', 28) === 0) {
         $token = substr($URI, 28);
         $mark = strpos($token, '?');
         if ($mark !== false) {
            $token = substr($token, 0, $mark);
         }

         $authorization = Challenges::load($token);
         if ($authorization === null) {
            return "HTTP/1.1 404 Not Found\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
         }

         $length = strlen($authorization);
         $body = $method === 'HEAD' ? '' : $authorization;
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nCache-Control: no-store\r\nConnection: close\r\nContent-Length: {$length}\r\n\r\n{$body}";
      }

      // : Everything else → HTTPS (the Caddy model)
      //   (the helper only exists under Auto-TLS — see guard()/prime())
      $domains = $this->AutoTLS->domains ?? [];
      $host = $domains[0] ?? $this->domain ?? 'localhost';
      if (preg_match('/^Host:[ \t]*([^\r\n]+)/mi', $head, $matches) === 1) {
         $candidate = strtolower(trim($matches[1]));
         // Hostnames may carry one :port suffix; bracketed/raw IPv6 is never
         // split at its first colon and is not reflected unless configured.
         if (str_starts_with($candidate, '[')) {
            $end = strpos($candidate, ']');
            $candidate = $end !== false ? substr($candidate, 1, $end - 1) : '';
         }
         else if (substr_count($candidate, ':') === 1) {
            $candidate = explode(':', $candidate, 2)[0];
         }

         // ? Only a configured SAN is reflected — an arbitrary Host would
         //   make this an open redirect
         if (in_array($candidate, $domains, true)) {
            $host = $candidate;
         }
      }
      $port = $this->port !== null && $this->port !== 443 ? ":{$this->port}" : '';

      $authority = str_contains($host, ':') ? "[{$host}]" : $host;

      return "HTTP/1.1 308 Permanent Redirect\r\nLocation: https://{$authority}{$port}{$URI}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
   }

   /**
    * Auto-TLS master pump: helper watchdog + manifest watch (15s) and the
    * renewal-need check (60s while no valid certificate — first boot,
    * backoff-guarded — else every 12h) that forks the certifier child.
    */
   private function supervise (): void
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return;
      }

      $now = time();

      // # Swap convergence is checked every master tick (0.5s in daemon /
      //   foreground), independently from the slower manifest/helper watch.
      $this->reconcile();

      // # Helper watchdog + manifest watch (every 15s)
      if ($now - $this->watched >= 15) {
         $this->watched = $now;

         // @ (Re)fork the helper — covers a dead helper, a failed first
         //   fork and the deferred Daemon-mode start (the gate fd is still
         //   open in the master, so the fresh child inherits it)
         if ($this->validator > 0) {
            if ($this->delegate($AutoTLS) === false) {
               $this->bind();
            }
         }
         else if ($this->helper > 0 && $this->alive($this->helper) === false) {
            pcntl_waitpid($this->helper, $status, WNOHANG);
            $this->helper = 0;
            $this->helperReady = false;
         }
         if ($this->helper === 0 && is_resource($this->Gate)) {
            $this->guard();
         }
         else if (is_resource($this->Gate) === false && $this->validator === 0) {
            $this->bind();
         }

         // Refresh the exported local helper PID/readiness after watchdog
         // recovery. The initial state is saved later by start().
         if ($this->Process->State->check()) {
            $this->Process->State->save($this->describe());
         }

         // @ Manifest watch — generation identity, not a reusable pathname,
         //   covers cross-instance issuance and lost wake-up signals.
         $Snapshot = $AutoTLS->snapshot();
         if (
            $Snapshot !== null
            && $Snapshot->generation !== $this->appliedGeneration
            && $Snapshot->generation !== $this->PendingSnapshot?->generation
         ) {
            $this->exchange();
         }
      }

      // # Renewal-need check — the next check is scheduled from the state
      //   we just observed: hourly-scale only while a valid certificate is
      //   far from its threshold; 60s while bootstrapped or due (the
      //   backoff file gates the actual attempts)
      if ($now < $this->checked) {
         return;
      }

      $days = $AutoTLS->Certificates->inspect();
      $due = $AutoTLS->check() === false
         || $days === null
         || $days <= $AutoTLS->threshold;

      $this->checked = $now + ($due ? 60 : 43200);

      if ($due === false) {
         return;
      }

      // A locally owned gate must have a helper that explicitly reached its
      // event loop before an ACME validation can be triggered.
      if ($this->helperReady === false) {
         return;
      }

      // ? A certifier is still running (parentage-checked — a recycled PID
      //   never suppresses renewal)
      if ($this->certifier > 0 && $this->alive($this->certifier)) {
         return;
      }
      if ($this->certifier > 0) {
         pcntl_waitpid($this->certifier, $status, WNOHANG);
      }
      $this->certifier = 0;

      $this->certify();
   }

   /**
    * Whether a PID is a live child of THIS process (`/proc` parentage
    * check) — `posix_kill($PID, 0)` alone would confuse a recycled PID
    * owned by an unrelated process with our helper/certifier.
    */
   private function alive (int $PID): bool
   {
      $status = @file_get_contents("/proc/{$PID}/status");
      if (is_string($status) === false) {
         return false;
      }

      // :
      return preg_match('/^State:\s+Z/m', $status) !== 1
         && preg_match('/^PPid:\t(\d+)$/m', $status, $matches) === 1
         && (int) $matches[1] === posix_getpid();
   }

   /**
    * Fork the certifier child: obtain/renew the certificate in background
    * (lock-, threshold- and backoff-guarded by `AutoTLS::renew()`), then
    * signal the master to hot-swap. Never joins `Process->Children`.
    */
   private function certify (): void
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return;
      }

      $PID = pcntl_fork();

      if ($PID < 0) {
         $this->Logger->log(error: '@\\;Auto-TLS: failed to fork the certifier.@\\;');
         return;
      }

      // ?: Master
      if ($PID > 0) {
         $this->certifier = $PID;
         return;
      }

      // # Certifier child
      cli_set_process_title("{$this->process}: ACME certifier");
      $this->Process->State->detach();
      $master = Process::$master;

      foreach ([
         SIGALRM, SIGUSR1, SIGURG, SIGHUP, SIGINT, SIGQUIT, SIGTERM,
         SIGTSTP, SIGCONT, SIGUSR2, SIGCHLD, SIGIOT, SIGIO
      ] as $signal) {
         pcntl_signal($signal, SIG_DFL);
      }

      // ! A stalled CA must not turn a hard-killed master into a permanent
      //   orphan holding renew.lock. SIGALRM interrupts blocking transport
      //   syscalls; async dispatch then verifies the exact original parent.
      pcntl_async_signals(true);
      pcntl_signal(SIGALRM, static function () use ($master): void {
         if (posix_getppid() !== $master) {
            exit(1);
         }
         pcntl_alarm(1);
      });
      if (posix_getppid() !== $master) {
         exit(1);
      }
      pcntl_alarm(1);

      // The certifier never accepts application traffic.
      foreach ($this->Listeners as $Listener) {
         fclose($Listener);
      }
      $this->Listeners = [];

      // ! The inherited HTTP-01 gate belongs to the master/helper — the
      //   certifier must not hold the descriptor open
      if (is_resource($this->Gate)) {
         fclose($this->Gate);
         $this->Gate = null;
      }

      try {
         $swapped = $AutoTLS->renew();
      }
      catch (Throwable $Throwable) {
         // ! Backoff already recorded by renew(); the server keeps serving
         //   the current certificate — zero availability impact
         $this->Logger->log(
            error: "@\\;Auto-TLS: issuance failed — {$Throwable->getMessage()}@\\;"
         );
         exit(1);
      }

      // @ Publish the exact desired generation, then wake the master. The
      //   manifest watch remains a fallback when either operation fails.
      if ($swapped) {
         $Snapshot = $AutoTLS->snapshot(allowBootstrap: false);
         if ($Snapshot === null || $AutoTLS->Swaps->request($Snapshot) === null) {
            $this->Logger->log(
               error: '@\\;Auto-TLS: certificate installed but its generation request could not be published — the manifest watch will recover.@\\;'
            );
            exit(1);
         }

         if (posix_kill(Process::$master, SIGURG) === false) {
            $this->Logger->log(
               error: '@\\;Auto-TLS: generation published but the master wake-up failed — the manifest watch will recover.@\\;'
            );
            exit(1);
         }
      }

      pcntl_alarm(0);
      exit(0);
   }

   /**
    * Apply one exact generation. Workers acknowledge their local result;
    * the master advances `appliedGeneration` only after every current worker
    * reports matching hashes.
    */
   private function exchange (): bool
   {
      $AutoTLS = $this->AutoTLS;
      if ($AutoTLS === null) {
         return false;
      }

      $desired = $AutoTLS->Swaps->fetch();
      $generation = $desired['generation'] ?? null;
      $Snapshot = $AutoTLS->snapshot($generation);
      $level = isset($this->Process) ? $this->Process->level : 'master';
      // Once the currently published attempt is already applied, the
      // manifest watcher is allowed to advance to a newer generation and
      // publish a fresh attempt. A genuinely pending attempt remains pinned.
      if (
         $level === 'master'
         && ($desired === null || $desired['attempt'] === $this->appliedAttempt)
         && ($CurrentSnapshot = $AutoTLS->snapshot()) !== null
         && ($Snapshot === null || $CurrentSnapshot->generation !== $Snapshot->generation)
      ) {
         $Snapshot = $CurrentSnapshot;
         $desired = null;
      }
      // A stale request from a previous process/generation cannot pin the
      // master. Only the master may replace it with the current validated
      // manifest; workers wait for that authoritative publication.
      if ($Snapshot === null && $level === 'master') {
         $Snapshot = $AutoTLS->snapshot();
         $desired = null;
      }

      // ! The store no longer supplies the exact bytes this attempt pinned —
      //   it was removed, or replaced by other bytes. Structurally valid
      //   replacements are mismatches too: only the acknowledged hashes are
      //   the generation.
      $mismatch = $Snapshot === null
         || ($desired !== null && (
            $Snapshot->certificateHash !== $desired['certificateHash']
            || $Snapshot->keyHash !== $desired['keyHash']
         ));

      // A replacement worker is forked from the master and inherits its
      // still-retained private credential artifact. If the writable store was
      // removed or changed, recover from that exact inherited artifact instead
      // of adopting the new bytes or entering a crash loop. The pinned hashes
      // are the authority: swap() re-verifies the inherited bytes against them,
      // so an artifact from another generation is refused here just the same —
      // no dependency on the master having already recorded convergence.
      if (
         $level === 'child'
         && $mismatch
         && $desired !== null
         && $this->secure !== null
      ) {
         $success = $this->swap($this->secure, [
            'certificate' => $desired['certificateHash'],
            'key' => $desired['keyHash']
         ]);
         $acknowledged = $AutoTLS->Swaps->acknowledge(
            $desired['attempt'],
            $desired['generation'],
            posix_getpid(),
            $success,
            $desired['certificateHash'],
            $desired['keyHash'],
            $success ? '' : 'replacement worker rejected the inherited active artifact'
         );
         if ($success && $acknowledged) {
            $this->appliedGeneration = $desired['generation'];
            $this->appliedAttempt = $desired['attempt'];
         }

         return $success && $acknowledged;
      }

      if (
         $Snapshot === null
         || $mismatch
         || ($Snapshot->bootstrap && $this->appliedGeneration !== '' && $Snapshot->generation !== $this->appliedGeneration)
      ) {
         if ($level === 'child' && $desired !== null) {
            $AutoTLS->Swaps->acknowledge(
               $desired['attempt'],
               $desired['generation'],
               posix_getpid(),
               false,
               $desired['certificateHash'],
               $desired['keyHash'],
               'generation failed full validation or attempted a live bootstrap downgrade'
            );
         }
         $this->Logger->log(
            error: '@\\;Auto-TLS: requested generation failed hash/chain/pair/window/SAN validation — serving continues on the current certificate.@\\;'
         );
         return false;
      }

      $secure = $AutoTLS->options + $Snapshot->secure();
      if (self::$enableHTTP2) {
         $secure['alpn_protocols'] ??= 'h2,http/1.1';
      }

      if ($level === 'child' && $desired === null) {
         return false;
      }

      // # Worker — validate the same bytes again inside swap(), apply, probe
      //   the resulting context and publish an exact-generation acknowledgement.
      if ($level === 'child') {
         $success = $this->swap($secure, $Snapshot->hash());
         $acknowledged = $AutoTLS->Swaps->acknowledge(
            $desired['attempt'],
            $Snapshot->generation,
            posix_getpid(),
            $success,
            $Snapshot->certificateHash,
            $Snapshot->keyHash,
            $success ? '' : 'worker stream context rejected the validated generation'
         );
         if ($success && $acknowledged) {
            $this->applied = $Snapshot->certificate;
            $this->appliedGeneration = $Snapshot->generation;
            $this->appliedAttempt = $desired['attempt'];
         }

         return $success && $acknowledged;
      }

      // # Master — publish before signaling. Repeated/coalesced wake-ups only
      //   redispatch the same ATTEMPT. A new publication, even for the same
      //   generation, has a fresh identity and cannot consume stale ACKs.
      if (
         $desired === null
         || $desired['generation'] !== $Snapshot->generation
         || $desired['certificateHash'] !== $Snapshot->certificateHash
         || $desired['keyHash'] !== $Snapshot->keyHash
      ) {
         $attempt = $AutoTLS->Swaps->request($Snapshot);
         if ($attempt === null) {
            $this->Logger->log(error: '@\\;Auto-TLS: could not publish the desired swap generation.@\\;');
            return false;
         }
         $desired = $AutoTLS->Swaps->fetch();
         if ($desired === null || $desired['attempt'] !== $attempt) {
            $this->Logger->log(error: '@\\;Auto-TLS: desired swap attempt could not be read back safely.@\\;');
            return false;
         }
      }
      if ($this->swap($secure, $Snapshot->hash()) === false) {
         $this->Logger->log(error: '@\\;Auto-TLS: master rejected the validated swap generation.@\\;');
         return false;
      }

      if (
         $this->PendingSnapshot?->generation !== $Snapshot->generation
         || $this->pendingAttempt !== $desired['attempt']
      ) {
         $this->PendingSnapshot = $Snapshot;
         $this->pendingAttempt = $desired['attempt'];
         $this->pendingSince = microtime(true);
         $this->pendingAttempts = 0;
         $this->swapFallbackQueued = false;
      }

      if ($this->Process->Children->PIDs === []) {
         $this->complete($Snapshot);
         return $this->PendingSnapshot === null;
      }

      $this->Process->Signals->send(SIGURG, master: false, children: true);
      return true;
   }

   /** Check worker acknowledgements without blocking the master loop. */
   private function reconcile (): void
   {
      $Snapshot = $this->PendingSnapshot;
      $AutoTLS = $this->AutoTLS;
      if ($Snapshot === null || $AutoTLS === null || $this->Process->level !== 'master') {
         return;
      }

      $attempt = $this->pendingAttempt;
      if ($attempt === '') {
         $this->retry('the pending request attempt is missing');
         return;
      }
      $acks = $AutoTLS->Swaps->collect($Snapshot->generation, $attempt);
      $missing = [];
      $failed = [];
      foreach ($this->Process->Children->PIDs as $PID) {
         $ack = $acks[$PID] ?? null;
         if ($ack === null) {
            $missing[] = $PID;
            continue;
         }
         if (
            $ack['success'] === false
            || $ack['attempt'] !== $attempt
            || $ack['certificateHash'] !== $Snapshot->certificateHash
            || $ack['keyHash'] !== $Snapshot->keyHash
         ) {
            $failed[] = $PID;
         }
      }

      if ($missing === [] && $failed === []) {
         $this->complete($Snapshot);
         return;
      }
      if ($failed !== [] || microtime(true) - $this->pendingSince >= self::$swapAckTimeout) {
         $reason = $failed !== []
            ? 'worker rejection: ' . implode(',', $failed)
            : 'missing acknowledgements: ' . implode(',', $missing);
         $this->retry($reason);
      }
   }

   private function complete (CertificateSnapshot $Snapshot): void
   {
      $AutoTLS = $this->AutoTLS;
      $attempt = $this->pendingAttempt;
      if (
         $AutoTLS === null
         || $attempt === ''
         || $AutoTLS->Swaps->complete($Snapshot, $attempt) === false
      ) {
         $this->retry('the applied-generation record could not be persisted');
         return;
      }
      $AutoTLS->Swaps->prune($Snapshot->generation, $attempt);
      $this->applied = $Snapshot->certificate;
      $this->appliedGeneration = $Snapshot->generation;
      $this->appliedAttempt = $attempt;
      $this->PendingSnapshot = null;
      $this->pendingAttempt = '';
      $this->pendingAttempts = 0;
      $this->swapFallbackQueued = false;
      $this->Logger->log(
         info: "@\\;Auto-TLS: generation `{$Snapshot->generation}` acknowledged by every worker; certificate `{$Snapshot->certificate}` is active.@\\;"
      );
   }

   private function retry (string $reason): void
   {
      $Snapshot = $this->PendingSnapshot;
      if ($Snapshot === null) {
         return;
      }
      if ($this->pendingAttempts < self::$swapAckRetries) {
         $this->pendingAttempts++;
         $this->pendingSince = microtime(true);
         $this->Logger->log(
            warning: "@\\;Auto-TLS: generation `{$Snapshot->generation}` has not converged ({$reason}); retry {$this->pendingAttempts}/" . self::$swapAckRetries . '.@\\;'
         );
         $this->Process->Signals->send(SIGURG, master: false, children: true);
         return;
      }

      if ($this->swapFallbackQueued) {
         return;
      }
      $this->swapFallbackQueued = true;
      $this->PendingSnapshot = null;
      $this->pendingAttempt = '';
      $this->Logger->log(
         critical: "@\\;Auto-TLS: generation `{$Snapshot->generation}` failed to converge ({$reason}); scheduling bounded SIGUSR2 reload fallback.@\\;"
      );

      // ? The startup barrier owns its own failure path: a server that never
      //   started has nothing to reload into, and re-execing the master here
      //   would only rebuild the boot that just failed — the launcher must
      //   report the failure instead. Test mode records the exhausted state
      //   without replacing the harness.
      if ($this->starting === false && $this->Mode !== Modes::Test) {
         if (posix_kill(Process::$master, SIGUSR2) === false) {
            $this->Logger->log(
               critical: '@\\;Auto-TLS: SIGUSR2 fallback delivery failed; the generation remains unapplied and will be retried by the manifest watch.@\\;'
            );
            $this->swapFallbackQueued = false;
         }
      }
   }

   /**
    * Recursively hand a storage tree to the configured runtime user/group
    * (the demoted certifier and helper must read/write it). Root-created
    * ancestors inside the storage dir are handed over too — the runtime
    * identity must be able to TRAVERSE into its own tree.
    */
   private function own (string $directory): bool
   {
      // ? Only meaningful when running as root with a target user
      if (posix_getuid() !== 0 || ($this->user === null && $this->group === null)) {
         return true;
      }

      if (is_link($directory) || is_dir($directory) === false) {
         return false;
      }

      // ? Never operate through a symlinked ANCESTOR or root — a runtime
      //   user with write access could aim a privileged recursive chown
      //   elsewhere (containment; name-based, re-checked per boot)
      $walk = '';
      foreach (explode('/', trim($directory, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }

         $walk .= "/{$segment}";
         if (is_link($walk)) {
            return false;
         }
      }

      // ! Root-created ancestors (a privileged recursive mkdir defaults them
      //   to 0700 root) would strand the demoted runtime outside its own
      //   tree. Hand every root-owned ancestor inside the storage dir over
      //   as well — the directories themselves only, never their contents.
      $storage = rtrim(BOOTGLY_STORAGE_DIR, '/');
      $chain = [];
      $ancestor = rtrim($directory, '/');
      while (true) {
         $ancestor = dirname($ancestor);
         if (
            $ancestor === $storage
            || $ancestor === '/'
            || $ancestor === '.'
            || strlen($ancestor) <= strlen($storage)
         ) {
            break;
         }
         $chain[] = $ancestor;
      }
      if ($ancestor === $storage) {
         foreach (array_reverse($chain) as $parent) {
            $status = @stat($parent);
            if ($status === false) {
               return false;
            }
            if ($status['uid'] !== 0) {
               continue;
            }
            if ($this->user !== null && lchown($parent, $this->user) === false) {
               return false;
            }
            if ($this->group !== null && lchgrp($parent, $this->group) === false) {
               return false;
            }
         }
      }

      $entries = scandir($directory);
      if ($entries === false) {
         return false;
      }

      foreach ($entries as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }

         $path = "{$directory}/{$entry}";

         // ? A symlink anywhere in the managed tree makes the handoff
         //   ambiguous. Refuse the boot instead of silently skipping it.
         if (is_link($path)) {
            return false;
         }

         if ($this->user !== null && lchown($path, $this->user) === false) {
            return false;
         }
         if ($this->group !== null && lchgrp($path, $this->group) === false) {
            return false;
         }

         if (is_dir($path) && $this->own($path) === false) {
            return false;
         }
      }

      if ($this->user !== null && lchown($directory, $this->user) === false) {
         return false;
      }
      if ($this->group !== null && lchgrp($directory, $this->group) === false) {
         return false;
      }

      return true;
   }

   public function instance ()
   {
      $Socket = parent::instance();

      // @ Per-worker upload-counter hygiene (audit F-10). Runs in every
      //   (re)spawned worker — both the initial fork and the SIGCHLD refork
      //   reach the worker through `$this->instance()`. Sweep temp files
      //   orphaned by a crashed worker (older than `ORPHAN_TTL`, so a live
      //   in-flight upload is untouched) and reconcile the shared counter
      //   against the bytes actually on disk, healing any reservation a dead
      //   worker stranded on the shared counter.
      Downloads::sweep(Downloads::ORPHAN_TTL);
      Downloads::reconcile();

      return $Socket;
   }

   public static function boot (Environments $Environment, string $testsDir = 'E2E'): void
   {
      switch ($Environment) {
         case Environments::Test:
            try {
               // @ Propagate Test environment to SAPI so that Request::decode()
               //   parses & strips the `X-Bootgly-Test: N` harness header on
               //   the very first request. Without this, a worker booted via
               //   start() (before SIGUSR1 `@test init` has been dispatched)
               //   would leave Environment=Production, the header would be
               //   exposed to user code, and SAPI::$testIndexHeader would
               //   stay null — Encoder_Testing would then call an
               //   uninitialized SAPI::$Handler and return 500 / 503.
               SAPI::$Environment = Environments::Test;

               self::$Encoder = new Encoder_Testing;

               if (
                  isset(SAPI::$Suite)
                  && isset(SAPI::$Tests[self::class])
               ) {
                  break;
               }

               // @ Convert namespace to path (backslash -> forward slash)
               $classPath = str_replace('\\', '/', __CLASS__);

               // * Data
               // !
               $bootstrap = BOOTGLY_ROOT_DIR . $classPath . '/tests/' . $testsDir . '/' . BOOTSTRAP_FILENAME;
               $Bootstrap = new File($bootstrap);
               // ?
               if ($Bootstrap->exists === false) {
                  throw new Exception("Test Suite file not found: \n {$bootstrap}");
               }
               // @ Reset Cache of Test case file
               if (function_exists('opcache_invalidate')) {
                  opcache_invalidate($bootstrap, true);
               }
               clearstatcache(false, $bootstrap);

               $Suite = include $Bootstrap->file;
               // ?
               if ($Suite instanceof Suite === false) {
                  throw new Exception("Test Suite instance not found: \n {$bootstrap}");
               }
               self::pretest($Suite, $testsDir);
            }
            catch (Throwable $Throwable) {
               Exceptions::report($Throwable);
            }

            break;
         default:
            self::$Encoder = new Encoder_;

            // @ Exception reporting — `exceptions` log channel (registered once,
            //   pre-fork, so every worker inherits it; Test env skips it to keep
            //   E2E output byte-stable)
            static $reporting = false;
            if ($reporting === false) {
               $reporting = true;

               Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
                  static $Logger = null;
                  $Logger ??= new Logger(channel: 'exceptions', global: true);

                  $Logger->log(error: $Throwable->getMessage(), context: [
                     'class' => $Throwable::class,
                     'file' => $Throwable->getFile(),
                     'line' => $Throwable->getLine(),
                     ...$context
                  ]);
               };
            }
      }
   }

   /**
    * @param null|string $specs Absolute spec-directory override — lets a
    *                           platform (e.g. Web) run its own E2E specs
    *                           through this server's Test mode.
    */
   public static function pretest (null|Suite $Suite, string $testsDir = 'E2E', null|string $specs = null): void
   {
      if ($Suite === null) {
         return;
      }

      $originalTests = $Suite->tests;
      $target = $Suite->target ?? 0;

      $selected = [];
      if ($target > 0) {
         $index = $target - 1;
         if (isset($originalTests[$index])) {
            $selected[$index] = $originalTests[$index];
         }
      }
      else {
         foreach ($originalTests as $index => $case) {
            $selected[$index] = $case;
         }
      }

      SAPI::$Suite = $Suite;
      SAPI::$Tests[self::class] = [];
      SAPI::$tests[self::class] = [];

      // @ Convert namespace to path (backslash -> forward slash)
      $classPath = str_replace('\\', '/', __CLASS__);
      $specs ??= BOOTGLY_ROOT_DIR . $classPath . '/tests/' . $testsDir;

      foreach ($selected as $index => $case) {
         $Test_Case_File = new File(
            "{$specs}/{$case}.test.php"
         );
         if ($Test_Case_File->exists === false) {
            continue;
         }

         try {
            /** @var Specification|null $test */
            $test = self::load($Test_Case_File);
         }
         catch (Throwable) {
            $test = null;
         }

         if ($test instanceof Specification) {
            $test->index(case: $index + 1);
         }
         SAPI::$Tests[self::class][] = $test;
         SAPI::$tests[self::class][] = $case;

         // @ Expand handler queue for multi-request tests
         if (
            $test instanceof \Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification
            && $test->requests !== []
         ) {
            $extra = count($test->requests) - 1;
            for ($i = 0; $i < $extra; $i++) {
               SAPI::$Tests[self::class][] = $test;
            }
         }
      }

      $Suite->tests = SAPI::$tests[self::class];
   }
   /** Load one test Specification in an isolated local variable scope. */
   private static function load (File $File): mixed
   {
      return require $File;
   }
   protected static function test (TCP_Server_CLI $TCP_Server_CLI): bool
   {
      Display::show(Display::NONE);

      self::boot(Environments::Test);

      // @ MODE_TEST skips per-project State lock + shutdown SIGINT, so multiple
      //   test suites can run sequentially in the same master PHP process
      //   without blocking on flock() or tearing down the master on each
      //   client destruction.
      $TCP_Client_CLI = new TCP_Client_CLI(mode: TCP_Client_CLI::MODE_TEST);
      $TCP_Client_CLI->configure(
         host: ($TCP_Server_CLI->host === '0.0.0.0')
            ? '127.0.0.1'
            : ($TCP_Server_CLI->host ?? 'localhost'),
         port: $TCP_Server_CLI->port ?? 80,
      );
      $TCP_Client_CLI->on(
         TCP_Client_Events::ClientConnect,
         static function ($Socket, $Connection)
         use ($TCP_Client_CLI) 
         {
            Display::show(Display::MESSAGE);

            // ! Suite
            $Suite = SAPI::$Suite;
            $Suite->separate($Suite->name);

            // @ Reconnection closure (for tests that close the connection)
            $reconnect = static function () use ($TCP_Client_CLI, &$Socket): void {
               $context = stream_context_create([
                  'socket' => ['tcp_nodelay' => true]
               ]);
               $newSocket = @stream_socket_client(
                  "tcp://{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}",
                  $errno,
                  $errstr,
                  timeout: 5,
                  context: $context
               );
               if ($newSocket !== false) {
                  stream_set_blocking($newSocket, false);
                  $Socket = $newSocket;
               }
            };

            // @ Inject `X-Bootgly-Test: N` into the first HTTP request of a
            //   raw byte payload. Used for index-based handler dispatch (see
            //   `Server::boot($testIndex)` and `Encoder_Testing::encode()`).
            //   Leaves payloads that don't start with an HTTP request-line
            //   (pipelined/smuggled bytes, non-HTTP fuzz) untouched.
            $injectTestIndex = static function (string $bytes, int $testIndex): string {
               // Find the end of the request-line (first CRLF).
               $eol = strpos($bytes, "\r\n");
               if ($eol === false) {
                  return $bytes;
               }
               // Heuristic: only inject if the request-line looks like
               //   `METHOD SP request-target SP HTTP/x.y`.
               $requestLine = substr($bytes, 0, $eol);
               if (! preg_match('#^[A-Z]+ \S+ HTTP/\d\.\d$#', $requestLine)) {
                  return $bytes;
               }
               return substr($bytes, 0, $eol + 2)
                  . "X-Bootgly-Test: {$testIndex}\r\n"
                  . substr($bytes, $eol + 2);
            };
            // @ Complete a response whose body exceeded one socket read: when
            //   the headers advertise a Content-Length bigger than the bytes
            //   already received, keep reading exactly the missing remainder
            //   (large bodies — e.g. the built-in debug page — span several
            //   TCP reads).
            $completeBody = static function (string $input, string $request = '') use ($Connection, &$Socket): string {
               // ? HEAD responses carry Content-Length without a body (RFC 9110 §9.3.2)
               if (strncmp($request, 'HEAD ', 5) === 0) {
                  return $input;
               }
               // ? Only whole-header responses with a known body length
               $headerEnd = strpos($input, "\r\n\r\n");
               if ($headerEnd === false) {
                  return $input;
               }
               // ? Bodiless statuses (1xx/204/304) may still advertise a length
               if (preg_match('#^HTTP/\d\.\d (?:1\d\d|204|304) #', $input) === 1) {
                  return $input;
               }
               if (preg_match('#\r\nContent-Length: (\d+)\r\n#', substr($input, 0, $headerEnd + 2), $matches) !== 1) {
                  return $input;
               }

               $missing = ((int) $matches[1]) - (strlen($input) - $headerEnd - 4);
               if ($missing <= 0) {
                  return $input;
               }

               // @ Read exactly the missing remainder
               if ($Connection->reading($Socket, $missing, 2)) {
                  $input .= $Connection->input;
               }

               return $input;
            };

            // @@ Iterate Test Cases
            // !
            $testFiles = SAPI::$tests[self::class] ?? [];
            $specIndex = 0;
            foreach ($testFiles as $index => $value) {
               // @ Reset connection state from previous test
               $Connection->expired = false;
               $Connection->input = '';

               // @ Detect dead/stale connection from previous test's reject/close
               $r = [$Socket]; $w = null; $e = null;
               if (@feof($Socket) || @stream_select($r, $w, $e, 0, 10000) > 0) {
                  // @@ Drain stale data from socket buffer
                  while (@fread($Socket, 65535) !== '' && !@feof($Socket)) {}
                  // @ Reconnect with fresh socket
                  $reconnect();
               }

               /** @var Specification|null $test */
               $test = SAPI::$Tests[self::class][$specIndex] ?? null;

               if ($test instanceof Specification) {
                  $test->index(case: $test->case ?? ((int) $index + 1));
               }
               // @ Init Test
               $Suite->case = $test->case ?? ((int) $index + 1);

               $Test = $Suite->test($test);
               if ($Test === null || !($test instanceof \Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification)) {
                  $Suite->skip();
                  $specIndex++;
                  continue;
               }

               // @ Fixture lifecycle — early prepare() so request: closure can
               //   read seeded state. Idempotent: base Test::pretest() will
               //   call prepare() again later as a no-op.
               $test->Fixture?->prepare();

               // @ Multi-request test
               if ($test->requests !== []) {
                  $responses = [];
                  $failed = false;

                  foreach ($test->requests as $reqIndex => $requestClosure) {
                     // ! Server
                     $responseLength = $test->responseLengths[$reqIndex] ?? null;
                     // ! Client
                     // ? Request
                     try {
                        $requestArguments = ["{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex + $reqIndex];
                        $requestArguments = self::inject($requestArguments, $requestClosure, $test->Fixture);

                        $requestData = $requestClosure(...$requestArguments);
                     }
                     catch (Throwable $Throwable) {
                        $test->Fixture?->dispose();

                        throw $Throwable;
                     }
                     // @ Inject handler-dispatch header (index-based).
                     $requestData = $injectTestIndex($requestData, $specIndex + $reqIndex);
                     $requestLength = strlen($requestData);
                     // @ Send Request to Server
                     $Connection->output = $requestData;
                     if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                        // @ Reconnect and retry
                        $reconnect();
                        if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                           $failed = true;
                           break;
                        }
                     }
                     // ? Response
                     $timeout = 2;
                     $input = '';
                     // @ Get Response from Server
                     if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                        $input = $Connection->input;
                     }
                     // @ Reconnect and retry if response is empty (half-closed connection)
                     if ($input === '' && $Connection->expired) { // @phpstan-ignore identical.alwaysTrue, booleanAnd.rightAlwaysFalse
                        $reconnect();
                        $Connection->expired = false;
                        $Connection->output = $requestData;
                        if ($Connection->writing($Socket, $requestLength)) {
                           if ($Connection->reading($Socket, $responseLength, $timeout)) {
                              $input = $Connection->input;
                           }
                        }
                     }

                     if ($Connection->expired) { // @phpstan-ignore if.alwaysFalse
                        $failed = true;
                        break;
                     }

                     $responses[] = $input;
                  }

                  $specIndex += count($test->requests);

                  if ($failed) {
                     $test->Fixture?->dispose();

                     $Test->fail();
                     break;
                  }

                  // @ Execute Test
                  $Test->test($responses);
                  // @ Output Test result
                  if ($Test->passed) {
                     $Test->pass();
                  }
                  else {
                     $Test->fail();
                     break;
                  }

                  continue;
               }

               // @ Single-request test (existing behavior)
               $specIndex++;

               // ! Server
               $responseLength = $test->responseLength;
               // ! Client
               // ? Request
               $request = $test->request;
               try {
                  if ($request !== null) {
                     $requestArguments = ["{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex - 1];
                     $requestArguments = self::inject($requestArguments, $request, $test->Fixture);

                     $requestResult = $request(...$requestArguments);
                  }
                  else {
                     $requestResult = '';
                  }
               }
               catch (Throwable $Throwable) {
                  $test->Fixture?->dispose();

                  throw $Throwable;
               }

               // @ Generator: yield chunks with delay for server event loop
               if ($requestResult instanceof Generator) {
                  $injected = false;
                  try {
                     foreach ($requestResult as $chunk) {
                        /** @var string $chunk */
                        if (! $injected) {
                           $chunk = $injectTestIndex($chunk, $specIndex - 1);
                           $injected = true;
                        }
                        $chunkLength = strlen($chunk);
                        $Connection->output = $chunk;
                        if ( ! $Connection->writing($Socket, $chunkLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                           $reconnect();
                           if ( ! $Connection->writing($Socket, $chunkLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                              $test->Fixture?->dispose();

                              break 2;
                           }
                        }
                        usleep(10000); // 10ms for server event loop to process
                     }
                  }
                  catch (Throwable $Throwable) {
                     $test->Fixture?->dispose();

                     throw $Throwable;
                  }

                  // ? Response
                  $timeout = 2;
                  $input = '';
                  if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                     $input = $completeBody($Connection->input);
                  }

                  // @ Execute Test
                  $Test->test($input);
                  if (! $Connection->expired && $Test->passed) { // @phpstan-ignore booleanNot.alwaysTrue
                     $Test->pass();
                  }
                  else {
                     $Test->fail();
                     break;
                  }

                  continue;
               }

               $requestData = $requestResult;
               // @ Inject handler-dispatch header (index-based).
               //   `$specIndex` was pre-incremented above for single-request
               //   tests, so the correct slot is `$specIndex - 1`.
               $requestData = $injectTestIndex($requestData, $specIndex - 1);
               $requestLength = strlen($requestData);
               // @ Send Request to Server
               $Connection->output = $requestData;
               if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                  // @ Reconnect and retry
                  $reconnect();
                  if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                     $test->Fixture?->dispose();

                     $Test->fail();
                     break;
                  }
               }
               // ? Response
               $timeout = 2;
               $input = '';
               // @ Get Response from Server
               if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                  $input = $completeBody($Connection->input, $requestData);
               }
               // @ Reconnect and retry if response is empty (half-closed connection)
               if ($input === '' && $Connection->expired) { // @phpstan-ignore identical.alwaysTrue, booleanAnd.rightAlwaysFalse
                  $reconnect();
                  $Connection->expired = false;
                  $Connection->output = $requestData;
                  if ($Connection->writing($Socket, $requestLength)) {
                     if ($Connection->reading($Socket, $responseLength, $timeout)) {
                        $input = $completeBody($Connection->input, $requestData);
                     }
                  }
               }

               // @ Execute Test
               $Test->test($input);
               // @ Output Test result
               if (! $Connection->expired && $Test->passed) { // @phpstan-ignore booleanNot.alwaysTrue
                  $Test->pass();
               }
               else {
                  $Test->fail();
                  break;
               }
            }

            $Suite->summarize();

            // @ Reset CLI Logger
            Display::show(Display::MESSAGE);

            // @ Destroy Client Event Loop
            $TCP_Client_CLI::$Event->destroy();
         }
      );
      $TCP_Client_CLI->start();

      return true;
   }
   private static function check (null|ReflectionType $Type, Fixture $Fixture): bool
   {
      if ($Type === null) {
         return true;
      }

      if ($Type instanceof ReflectionUnionType) {
         foreach ($Type->getTypes() as $Inner) {
            if (self::check($Inner, $Fixture)) {
               return true;
            }
         }

         return false;
      }

      if ($Type instanceof ReflectionIntersectionType) {
         foreach ($Type->getTypes() as $Inner) {
            if (self::check($Inner, $Fixture) === false) {
               return false;
            }
         }

         return true;
      }

      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();
         if ($name === 'mixed' || $name === 'object') {
            return true;
         }
         if ($Type->isBuiltin()) {
            return false;
         }

         return is_a($Fixture, $name);
      }

      return false;
   }
   /**
    * @param array<int,mixed> $arguments
    *
    * @return array<int,mixed>
    */
   private static function inject (array $arguments, Closure $Closure, null|Fixture $Fixture): array
   {
      $arguments = array_values($arguments);

      if ($Fixture === null) {
         return $arguments;
      }

      $Function = new ReflectionFunction($Closure);
      $parameters = $Function->getParameters();
      $Parameter = $parameters[count($arguments)] ?? null;

      if ($Parameter === null) {
         foreach ($parameters as $Candidate) {
            if ($Candidate->isVariadic()) {
               $Parameter = $Candidate;
               break;
            }
         }
      }

      if ($Parameter === null) {
         return $arguments;
      }

      if (self::check($Parameter->getType(), $Fixture) === false) {
         return $arguments;
      }

      $arguments[] = $Fixture;

      return $arguments;
   }
}

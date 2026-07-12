<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


use const JSON_THROW_ON_ERROR;
use function basename;
use function bin2hex;
use function chmod;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function glob;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function rmdir;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;
use function umask;
use function unlink;
use InvalidArgumentException;
use JsonException;


/**
 * Generation-aware hot-swap rendezvous, namespaced per running server.
 *
 * The certifier/master atomically publishes one desired generation. Each worker
 * atomically acknowledges the exact generation and hashes it validated/applied.
 *
 * The rendezvous lives under `<base>/<instance>/` — a random, fork-inherited
 * namespace generated when the server is configured. Unrelated servers sharing
 * one storage base (even for the same SAN set) can therefore never clobber each
 * other's desired/applied attempts or acknowledgements. A namespace is never
 * reaped by a sibling: publishers may be short-lived forked children, so their
 * process lifetime cannot prove that the inherited server namespace is stale.
 */
final class Swaps
{
   public private(set) string $base;
   public private(set) string $instance;
   public private(set) string $path;


   public function __construct (string $base, string $instance)
   {
      $base = rtrim($base, '/') . '/';
      if (
         $base === '/'
         || str_starts_with($base, '/') === false
         || str_contains($base, '/../')
         || str_contains($base, '/./')
      ) {
         throw new InvalidArgumentException('Auto-TLS swap path must be a dedicated absolute directory.');
      }
      if (preg_match('/^[a-f0-9]{16}$/', $instance) !== 1) {
         throw new InvalidArgumentException('Auto-TLS swap instance must be a 16-hex namespace.');
      }
      $this->base = $base;
      $this->instance = $instance;
      $this->path = "{$base}{$instance}/";
   }

   public function request (CertificateSnapshot $Snapshot): null|string
   {
      // @ A publisher can be a short-lived bootstrap/certifier child while
      //   the inherited namespace remains owned by the live server master.
      //   Consequently no process PID can safely lease a namespace. Keep
      //   publication strictly instance-local and only remove artifacts from
      //   the pre-namespace layout whose names cannot belong to a live server.
      $this->sweep();

      $attempt = bin2hex(random_bytes(16));

      return $this->write("{$this->path}request.json", [
         'attempt' => $attempt,
         'generation' => $Snapshot->generation,
         'certificateHash' => $Snapshot->certificateHash,
         'keyHash' => $Snapshot->keyHash,
         'requested' => time()
      ]) ? $attempt : null;
   }

   /** Remove only pre-namespace rendezvous artifacts from the shared base. */
   private function sweep (): void
   {
      foreach (glob("{$this->base}*") ?: [] as $entry) {
         $name = basename($entry);
         if (is_link($entry)) {
            continue;
         }

         // ? Legacy layout — request/applied and generation directories
         //   lived directly on the base before instance namespaces
         if (is_file($entry)) {
            if ($name === 'request.json' || $name === 'applied.json') {
               @unlink($entry);
            }
            continue;
         }
         if (is_dir($entry) === false) {
            continue;
         }
         if (preg_match('/^[a-f0-9]{32}$/', $name) === 1) {
            $this->remove($entry);
         }
      }
   }

   /** Recursively remove one bounded rendezvous tree (2 levels of dirs). */
   private function remove (string $directory): void
   {
      foreach (glob("{$directory}/*") ?: [] as $child) {
         if (is_link($child)) {
            continue;
         }
         if (is_dir($child)) {
            foreach (glob("{$child}/*") ?: [] as $grandchild) {
               if (is_dir($grandchild) && is_link($grandchild) === false) {
                  foreach (glob("{$grandchild}/*") ?: [] as $file) {
                     @unlink($file);
                  }
                  @rmdir($grandchild);
                  continue;
               }
               @unlink($grandchild);
            }
            @rmdir($child);
            continue;
         }
         @unlink($child);
      }
      @rmdir($directory);
   }

   /** @return null|array{attempt:string,generation:string,certificateHash:string,keyHash:null|string,requested:int} */
   public function fetch (): null|array
   {
      $record = $this->read("{$this->path}request.json");
      if ($record === null) {
         return null;
      }
      $attempt = $record['attempt'] ?? null;
      $generation = $record['generation'] ?? null;
      $certificateHash = $record['certificateHash'] ?? null;
      $keyHash = $record['keyHash'] ?? null;
      $requested = $record['requested'] ?? null;
      if (
         is_string($attempt) === false || preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || is_string($generation) === false || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || is_string($certificateHash) === false || preg_match('/^[a-f0-9]{64}$/', $certificateHash) !== 1
         || ($keyHash !== null && (is_string($keyHash) === false || preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1))
         || is_int($requested) === false
      ) {
         return null;
      }

      return [
         'attempt' => $attempt,
         'generation' => $generation,
         'certificateHash' => $certificateHash,
         'keyHash' => $keyHash,
         'requested' => $requested
      ];
   }

   public function acknowledge (
      string $attempt,
      string $generation,
      int $PID,
      bool $success,
      string $certificateHash,
      null|string $keyHash,
      string $error = ''
   ): bool
   {
      if (
         preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || $PID < 1
         || preg_match('/^[a-f0-9]{64}$/', $certificateHash) !== 1
         || ($keyHash !== null && preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1)
      ) {
         return false;
      }

      return $this->write("{$this->path}{$generation}/{$attempt}/{$PID}.json", [
         'attempt' => $attempt,
         'generation' => $generation,
         'pid' => $PID,
         'success' => $success,
         'certificateHash' => $certificateHash,
         'keyHash' => $keyHash,
         'error' => substr($error, 0, 512),
         'acknowledged' => time()
      ]);
   }

   /**
    * @return array<int,array{attempt:string,generation:string,pid:int,success:bool,certificateHash:string,keyHash:null|string,error:string,acknowledged:int}>
    */
   public function collect (string $generation, string $attempt): array
   {
      if (
         preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
      ) {
         return [];
      }
      $acks = [];
      $directory = "{$this->path}{$generation}/{$attempt}/";
      if ($this->validate($directory) === false || is_link($directory) || is_dir($directory) === false) {
         return [];
      }
      foreach (glob("{$directory}*.json") ?: [] as $file) {
         if (is_link($file)) {
            continue;
         }
         $record = $this->read($file);
         $PID = $record['pid'] ?? null;
         if (
            $record === null
            || ($record['attempt'] ?? null) !== $attempt
            || ($record['generation'] ?? null) !== $generation
            || is_int($PID) === false || $PID < 1
            || basename($file) !== "{$PID}.json"
            || is_bool($record['success'] ?? null) === false
            || is_string($record['certificateHash'] ?? null) === false
            || preg_match('/^[a-f0-9]{64}$/', $record['certificateHash']) !== 1
            || (($record['keyHash'] ?? null) !== null && (
               is_string($record['keyHash']) === false
               || preg_match('/^[a-f0-9]{64}$/', $record['keyHash']) !== 1
            ))
            || is_string($record['error'] ?? null) === false
            || is_int($record['acknowledged'] ?? null) === false
         ) {
            continue;
         }
         /** @var array{attempt:string,generation:string,pid:int,success:bool,certificateHash:string,keyHash:null|string,error:string,acknowledged:int} $record */
         $acks[$PID] = $record;
      }

      return $acks;
   }

   public function complete (CertificateSnapshot $Snapshot, string $attempt): bool
   {
      $request = $this->fetch();
      if (
         preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || $request === null
         || $request['attempt'] !== $attempt
         || $request['generation'] !== $Snapshot->generation
         || $request['certificateHash'] !== $Snapshot->certificateHash
         || $request['keyHash'] !== $Snapshot->keyHash
      ) {
         return false;
      }

      return $this->write("{$this->path}applied.json", [
         'attempt' => $attempt,
         'generation' => $Snapshot->generation,
         'certificateHash' => $Snapshot->certificateHash,
         'keyHash' => $Snapshot->keyHash,
         'applied' => time()
      ]);
   }

   public function resolve (): null|string
   {
      $record = $this->read("{$this->path}applied.json");
      $generation = $record['generation'] ?? null;

      return is_string($generation) && preg_match('/^[a-f0-9]{32}$/', $generation) === 1
         ? $generation
         : null;
   }

   /** Remove acknowledgement files from superseded generations. */
   public function prune (string $keep, string $attempt): void
   {
      foreach (glob("{$this->path}*") ?: [] as $entry) {
         $name = basename($entry);
         if (preg_match('/^[a-f0-9]{32}$/', $name) !== 1 || is_link($entry)) {
            continue;
         }

         foreach (glob("{$entry}/*") ?: [] as $attemptDirectory) {
            $attemptName = basename($attemptDirectory);
            // Upgrade cleanup: round-8 ACKs lived directly under the
            // generation as `<PID>.json` before attempts were introduced.
            if (
               is_link($attemptDirectory) === false
               && is_file($attemptDirectory)
               && preg_match('/^[1-9][0-9]*\.json$/', $attemptName) === 1
            ) {
               @unlink($attemptDirectory);
               continue;
            }
            if (
               $name === $keep && $attemptName === $attempt
               || preg_match('/^[a-f0-9]{32}$/', $attemptName) !== 1
               || is_link($attemptDirectory)
               || is_dir($attemptDirectory) === false
            ) {
               continue;
            }
            foreach (glob("{$attemptDirectory}/*.json") ?: [] as $file) {
               @unlink($file);
            }
            @rmdir($attemptDirectory);
         }
         if ($name !== $keep) {
            @rmdir($entry);
         }
      }
   }

   /** @param array<string,mixed> $record */
   private function write (string $file, array $record): bool
   {
      $directory = dirname($file) . '/';
      if ($this->validate($directory) === false) {
         return false;
      }
      if (is_dir($directory) === false && mkdir($directory, 0700, true) === false && is_dir($directory) === false) {
         return false;
      }
      // @phpstan-ignore identical.alwaysFalse (intentional post-mkdir TOCTOU recheck)
      if ($this->validate($directory) === false || chmod($directory, 0700) === false) {
         return false;
      }

      try {
         $JSON = json_encode($record, JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return false;
      }

      $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
      $previousMask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }
      $written = false;
      try {
         $length = strlen($JSON);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($JSON, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }
         $written = $offset === $length
            && fflush($Handle)
            && (!function_exists('fsync') || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($written === false) {
            @unlink($temporary);
         }
      }

      if ($written === false) {
         return false;
      }
      if (rename($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      return true;
   }

   /** @return null|array<string,mixed> */
   private function read (string $file): null|array
   {
      if ($this->validate($file) === false || is_link($file) || is_file($file) === false) {
         return null;
      }
      $JSON = file_get_contents($file, false, null, 0, 65537);
      if (is_string($JSON) === false || strlen($JSON) > 65536) {
         return null;
      }
      $record = json_decode($JSON, true);
      if (is_array($record) === false) {
         return null;
      }
      foreach ($record as $key => $_) {
         if (is_string($key) === false) {
            return null;
         }
      }
      /** @var array<string,mixed> $record */

      return $record;
   }

   private function validate (string $file): bool
   {
      if (
         str_starts_with($file, $this->path) === false
         || str_contains("{$file}/", '/../')
         || str_contains("{$file}/", '/./')
      ) {
         return false;
      }
      $walk = '';
      foreach (explode('/', trim($file, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }
         $walk .= "/{$segment}";
         if (is_link($walk)) {
            return false;
         }
      }

      return true;
   }
}

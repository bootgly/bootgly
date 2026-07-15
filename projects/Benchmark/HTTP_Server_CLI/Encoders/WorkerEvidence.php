<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\HTTP_Server_CLI\Encoders;


use Closure;
use Generator;
use function bin2hex;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function getenv;
use function getmypid;
use function hash;
use function hash_equals;
use function is_array;
use function is_dir;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strlen;
use function substr;
use function unlink;

use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * Benchmark-only encoder wrapper for worker readiness evidence.
 *
 * A warmed Router can resolve directly from its route cache and bypass global
 * middleware. This wrapper is therefore installed lazily after the production
 * encoder is selected in each worker. A valid nonce-bound request receives a
 * worker acknowledgement; a paired seal restores the exact original Encoder
 * and SAPI Handler before measurement.
 */
final class WorkerEvidence extends Encoders
{
   private static bool $booted = false;
   private static string $token;
   private static ?int $PID = null;
   private static ?string $workerIdentity = null;
   /** @var resource|null */
   private static mixed $lease = null;
   private static Encoder $Encoder;
   private static Closure $Handler;


   /**
    * Wrap the project Handler with worker-local evidence setup and teardown.
    */
   public function wrap (string $token, Closure $Handler): Closure
   {
      $Evidence = $this;

      return static function
      (Request $Request, Response $Response, Router $Router)
      use ($Evidence, $Handler, $token): Generator
      {
         $OriginalEncoder = HTTP_Server_CLI::$Encoder;

         if ($OriginalEncoder instanceof Encoder) {
            $Evidence->boot($token, $OriginalEncoder, $Handler);
            HTTP_Server_CLI::$Encoder = $Evidence;
         }

         $sealed = $Evidence->mark($Request, $Response);

         try {
            yield from $Handler($Request, $Response, $Router);
         }
         finally {
            // ? A seal can legally be the worker's first routed request; the
            //   current normal encoder still owns that response, so restoring
            //   here does not remove its already-set header.
            if ($sealed) {
               self::restore();
            }
         }
      };
   }

   public function boot (string $token, Encoder $Encoder, Closure $Handler): void
   {
      if (self::$booted) {
         return;
      }

      self::$booted = true;
      self::$token = $token;
      self::$Encoder = $Encoder;
      self::$Handler = $Handler;
   }

   public function mark (Request $Request, Response $Response): bool
   {
      $providedToken = $Request->Header->get('X-Bootgly-Benchmark-Warmup');
      $providedNonce = $Request->Header->get('X-Bootgly-Benchmark-Nonce');

      if (
         !self::check()
         || !is_string($providedToken)
         || !hash_equals(self::$token, $providedToken)
         || !is_string($providedNonce)
         || preg_match('/\A[0-9a-f]{64}\z/D', $providedNonce) !== 1
      ) {
         return false;
      }

      $Response->Header->set(
         'X-Bootgly-Benchmark-Worker',
         self::$token . ':' . $providedNonce . ':' . self::$workerIdentity,
      );

      $providedSeal = $Request->Header->get('X-Bootgly-Benchmark-Seal');

      return is_string($providedSeal)
         && hash_equals(self::$token, $providedSeal);
   }

   public static function restore (): void
   {
      HTTP_Server_CLI::$Encoder = self::$Encoder;
      SAPI::$Handler = self::$Handler;
   }

   public static function encode (Packages $Packages, null|int &$length): string
   {
      $Request = HTTP_Server_CLI::$Request;
      $providedToken = $Request->Header->get('X-Bootgly-Benchmark-Warmup');
      $providedNonce = $Request->Header->get('X-Bootgly-Benchmark-Nonce');
      $Encoder = self::$Encoder;

      if (
         !self::check()
         || !is_string($providedToken)
         || !hash_equals(self::$token, $providedToken)
         || !is_string($providedNonce)
         || preg_match('/\A[0-9a-f]{64}\z/D', $providedNonce) !== 1
      ) {
         return $Encoder::encode($Packages, $length);
      }

      $Response = HTTP_Server_CLI::$Response;
      $Response->Header->preset(
         'X-Bootgly-Benchmark-Worker',
         self::$token . ':' . $providedNonce . ':' . self::$workerIdentity,
      );

      $providedSeal = $Request->Header->get('X-Bootgly-Benchmark-Seal');
      $sealed = is_string($providedSeal)
         && hash_equals(self::$token, $providedSeal);

      try {
         return $Encoder::encode($Packages, $length);
      }
      finally {
         // ! For deferred responses the normal encoder has already cloned
         //   Response, so the clone keeps the acknowledgement while the worker
         //   singleton is clean for the next request.
         $Response->Header->preset('X-Bootgly-Benchmark-Worker');

         if ($sealed) {
            self::restore();
         }
      }
   }

   /** Whether this exact process ran the serving-worker lifecycle hook. */
   private static function check (): bool
   {
      $PID = getmypid();

      return is_int($PID)
         && self::$PID === $PID
         && self::$workerIdentity !== null;
   }

   /**
    * Register one process-local identity and retain its exclusive lease until
    * this worker exits. Persist neither token nor raw identity; the runner
    * binds the lease fingerprint to warmup proof.
    */
   public static function register (): void
   {
      $PID = getmypid();
      if (!is_int($PID) || $PID < 1) {
         throw new \RuntimeException('Could not resolve the worker evidence process ID.');
      }
      if (self::$PID === $PID && self::$workerIdentity !== null) {
         return;
      }

      // ! Forked children inherit statics and descriptors. Close the child's
      //   duplicate before allocating its distinct identity; the parent's
      //   descriptor continues to hold the original lock.
      if (is_resource(self::$lease)) {
         fclose(self::$lease);
      }
      self::$lease = null;
      self::$PID = $PID;
      self::$workerIdentity = null;

      $serverDirectory = getenv('BENCHMARK_SERVER_DIR');
      if (!is_string($serverDirectory) || $serverDirectory === '') {
         self::$workerIdentity = $PID . '-' . bin2hex(random_bytes(16));

         return;
      }
      if (!is_dir($serverDirectory) || is_link($serverDirectory)) {
         throw new \RuntimeException('Worker evidence server directory is unavailable or unsafe.');
      }

      $directory = rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/workers';
      if (
         !is_dir($directory)
         && !@mkdir($directory, 0o700)
         && !is_dir($directory)
      ) {
         throw new \RuntimeException('Could not create the worker evidence directory.');
      }
      if (is_link($directory)) {
         throw new \RuntimeException('Worker evidence directory must not be a symbolic link.');
      }

      for ($attempt = 0; $attempt < 16; $attempt++) {
         $identity = $PID . '-' . bin2hex(random_bytes(16));
         $SHA = hash('sha256', "worker\0{$identity}");
         $fingerprint = 'sha256:' . $SHA;
         $file = $directory . '/worker-' . $SHA . '.lease';
         $Handle = @fopen($file, 'x+b');

         if ($Handle === false) {
            if (file_exists($file) || is_link($file)) {
               continue;
            }

            throw new \RuntimeException('Could not create the worker evidence lease.');
         }

         $registered = false;
         try {
            if (!@chmod($file, 0o600)) {
               throw new \RuntimeException('Could not protect the worker evidence lease.');
            }
            $metadata = fstat($Handle);
            if (!is_array($metadata) || ($metadata['mode'] & 0o777) !== 0o600) {
               throw new \RuntimeException('Worker evidence lease permissions are invalid.');
            }
            if (!flock($Handle, LOCK_EX | LOCK_NB)) {
               throw new \RuntimeException('Could not lock the worker evidence lease.');
            }

            $JSON = json_encode([
               'schema' => 'bootgly.worker-lease',
               'version' => 1,
               'fingerprint' => $fingerprint,
               'pid' => $PID,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $contents = $JSON . "\n";
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
               $written = fwrite($Handle, substr($contents, $offset));
               if ($written === false || $written === 0) {
                  throw new \RuntimeException('Could not write the worker evidence lease.');
               }
               $offset += $written;
            }
            if (!fflush($Handle) || (function_exists('fsync') && !fsync($Handle))) {
               throw new \RuntimeException('Could not sync the worker evidence lease.');
            }

            self::$workerIdentity = $identity;
            self::$lease = $Handle;
            $registered = true;

            return;
         }
         finally {
            if (!$registered) {
               fclose($Handle);
               @unlink($file);
            }
         }
      }

      throw new \RuntimeException('Could not allocate a unique worker evidence lease.');
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use const SORT_STRING;
use function array_any;
use function basename;
use function bin2hex;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function getmypid;
use function gmdate;
use function implode;
use function is_dir;
use function microtime;
use function mkdir;
use function preg_match;
use function random_bytes;
use function realpath;
use function rename;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function umask;
use function unlink;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;


/**
 * Own every artifact produced by one benchmark invocation.
 *
 * A run directory is claimed with an exclusive mkdir(). Files are first fully
 * written to an exclusive sibling temporary file and then atomically renamed.
 */
final class Artifacts
{
   public readonly string $ID;
   public readonly string $directory;
   public readonly string $relativeDirectory;
   public readonly string $pathBase;


   private function __construct (string $ID, string $directory)
   {
      $this->ID = $ID;
      $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);

      $working = rtrim(BOOTGLY_WORKING_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      $this->pathBase = rtrim(BOOTGLY_WORKING_DIR, DIRECTORY_SEPARATOR);
      $this->relativeDirectory = str_starts_with($this->directory . DIRECTORY_SEPARATOR, $working)
         ? substr($this->directory, strlen($working))
         : $this->directory;
   }

   /**
    * Claim a collision-resistant run workspace without overwrite on collision.
    */
   public static function create (string $caseName, null|string $root = null): self
   {
      if (
         preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $caseName) !== 1
         || $caseName === '.'
         || $caseName === '..'
      ) {
         throw new RuntimeException("Invalid benchmark case name: {$caseName}");
      }

      $root ??= BOOTGLY_STORAGE_DIR . "tests/benchmarks/{$caseName}/runs";
      $root = rtrim($root, DIRECTORY_SEPARATOR);

      if (is_dir($root) === false && @mkdir($root, 0775, true) === false && is_dir($root) === false) {
         throw new RuntimeException("Can not create benchmark run root: {$root}");
      }

      // ! The random component makes a collision impractical; exclusive mkdir()
      //   is the actual filesystem-enforced collision guarantee.
      for ($attempt = 0; $attempt < 16; $attempt++) {
         $time = sprintf('%.6F', microtime(true));
         [$seconds, $fraction] = explode('.', $time, 2);
         $PID = getmypid();
         $ID = gmdate('Ymd\THis', (int) $seconds)
            . ".{$fraction}Z-p{$PID}-"
            . bin2hex(random_bytes(16));
         $directory = "{$root}/{$ID}";

         if (@mkdir($directory, 0775) === true) {
            return new self($ID, $directory);
         }
      }

      throw new RuntimeException("Can not claim a unique benchmark run directory below: {$root}");
   }

   /**
    * Reopen the workspace passed to a supervised benchmark child.
    */
   public static function open (string $ID, string $directory): self
   {
      if (
         preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $ID) !== 1
         || $ID === '.'
         || $ID === '..'
      ) {
         throw new RuntimeException('Invalid benchmark run ID');
      }

      $resolved = realpath($directory);
      if ($resolved === false || is_dir($resolved) === false || basename($resolved) !== $ID) {
         throw new RuntimeException("Invalid benchmark run directory: {$directory}");
      }

      return new self($ID, $resolved);
   }

   /**
    * Resolve a run-relative file and create its parent directory.
    */
   public function resolve (string $relative): string
   {
      $relative = str_replace('\\', '/', $relative);
      $segments = explode('/', $relative);

      if (
         $relative === ''
         || str_starts_with($relative, '/')
         || str_contains($relative, "\0")
         || array_any($segments, static fn (string $segment): bool
            => $segment === '' || $segment === '.' || $segment === '..')
      ) {
         throw new RuntimeException("Invalid run-relative artifact path: {$relative}");
      }

      $file = $this->directory . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
      $parent = dirname($file);
      if (is_dir($parent) === false && @mkdir($parent, 0775, true) === false && is_dir($parent) === false) {
         throw new RuntimeException("Can not create artifact directory: {$parent}");
      }

      return $file;
   }

   /**
    * Atomically write a run-relative artifact and return its display path.
    */
   public function write (string $relative, string $contents): string
   {
      self::commit($this->resolve($relative), $contents);

      return $this->relate($relative);
   }

   /**
    * Relate a run-relative artifact to the declared framework path base.
    */
   public function relate (string $relative): string
   {
      // @ Validate the relative path through the same resolver. Its parent may
      //   be created here, which is harmless inside this invocation-owned tree.
      $file = $this->resolve($relative);
      $working = rtrim(BOOTGLY_WORKING_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

      return str_starts_with($file, $working)
         ? substr($file, strlen($working))
         : $file;
   }

   /**
    * Collect only files below this invocation's exclusive workspace.
    *
    * @return array<int,string>
    */
   public function collect (): array
   {
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS)
      );
      $files = [];

      foreach ($Iterator as $File) {
         if (!$File instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected benchmark artifact iterator entry');
         }

         if ($File->isFile() === false || $File->isLink()) {
            continue;
         }

         $relative = substr($File->getPathname(), strlen($this->directory) + 1);
         $files[] = $this->relate($relative);
      }

      sort($files, SORT_STRING);

      return $files;
   }

   /**
    * Atomically publish a complete file in its destination directory.
    */
   public static function commit (string $file, string $contents): void
   {
      $parent = dirname($file);
      if (is_dir($parent) === false && @mkdir($parent, 0775, true) === false && is_dir($parent) === false) {
         throw new RuntimeException("Can not create artifact directory: {$parent}");
      }

      $temporary = $file . '.' . bin2hex(random_bytes(16)) . '.tmp';
      $previousMask = umask(0022);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }

      if ($Handle === false) {
         throw new RuntimeException("Can not create temporary artifact: {$temporary}");
      }

      $complete = false;
      try {
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($contents, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }

         $complete = $offset === $length
            && fflush($Handle)
            && (function_exists('fsync') === false || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($complete === false) {
            @unlink($temporary);
         }
      }

      if ($complete === false || @rename($temporary, $file) === false) {
         @unlink($temporary);
         throw new RuntimeException("Can not atomically publish artifact: {$file}");
      }
   }
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Driver;


/**
 * Whether the S3 E2E tests should be skipped (no endpoint configured).
 *
 * Set BOOTGLY_S3_BUCKET (+ REGION/KEY/SECRET and, for S3-compatible servers,
 * ENDPOINT/PATH_STYLE) to run them against a real S3 / MinIO endpoint.
 */
function s3_skip (): bool
{
   // ? Require bucket + credentials so a partial env never fires blank-cred requests
   foreach (['BOOTGLY_S3_BUCKET', 'BOOTGLY_S3_KEY', 'BOOTGLY_S3_SECRET'] as $variable) {
      $value = getenv($variable);
      if ($value === false || $value === '') {
         return true;
      }
   }

   return false;
}

/**
 * Build a Storage facade whose default disk is the env-configured S3 driver
 * (`s3` is a built-in driver, so no registration is needed).
 */
function s3_storage (): Storage
{
   return new Storage([
      'default' => 's3',
      'disks' => [
         's3' => [
            'driver' => 's3',
            'bucket' => (string) getenv('BOOTGLY_S3_BUCKET'),
            'region' => getenv('BOOTGLY_S3_REGION') !== false ? (string) getenv('BOOTGLY_S3_REGION') : 'us-east-1',
            'key' => (string) getenv('BOOTGLY_S3_KEY'),
            'secret' => (string) getenv('BOOTGLY_S3_SECRET'),
            'endpoint' => getenv('BOOTGLY_S3_ENDPOINT') !== false ? (string) getenv('BOOTGLY_S3_ENDPOINT') : '',
            'path_style' => getenv('BOOTGLY_S3_PATH_STYLE') === '1',
            // MinIO E2E runs over http — opt in to insecure transport for the test endpoint
            'insecure' => true,
         ],
      ],
   ]);
}

/**
 * Whether the cases that run against the local S3 fake (`fixtures/S3.php`) must be skipped:
 * the fake forks its server, so it needs `pcntl` and `posix`.
 */
function fake_skip (): bool
{
   return DIRECTORY_SEPARATOR === '\\'
      || function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false;
}

/**
 * Send one bare HTTP request — no credentials, no signing, the URL exactly as given — and
 * return `[status, headers (lowercased names), body]`: what whoever holds a presigned URL
 * does with it (test helper).
 *
 * @param array<string,string> $headers
 *
 * @return array{0:int,1:array<string,string>,2:string}
 */
function fake_send (string $method, string $URL, string $body = '', array $headers = []): array
{
   // ?
   if (preg_match('#^http://([^/:]+):(\d+)(/.*)$#', $URL, $matches) !== 1) {
      return [0, [], ''];
   }
   $Socket = @stream_socket_client("tcp://{$matches[1]}:{$matches[2]}", $code, $message, 5);
   if ($Socket === false) {
      return [0, [], ''];
   }
   stream_set_timeout($Socket, 10);

   // @
   $raw = "{$method} {$matches[3]} HTTP/1.1\r\nHost: {$matches[1]}:{$matches[2]}\r\nConnection: close\r\n";
   foreach ($headers as $name => $value) {
      $raw .= "{$name}: {$value}\r\n";
   }
   $raw .= 'Content-Length: ' . strlen($body) . "\r\n\r\n{$body}";
   fwrite($Socket, $raw);
   $response = (string) stream_get_contents($Socket);
   fclose($Socket);

   [$head, $answer] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
   $lines = explode("\r\n", $head);
   $status = (int) (explode(' ', (string) array_shift($lines), 3)[1] ?? 0);
   $answered = [];
   foreach ($lines as $line) {
      [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
      $answered[strtolower(trim($name))] = trim($value);
   }

   // :
   return [$status, $answered, $answer];
}

/**
 * Whether any argument of any frame of a stack trace holds `$needle` — strings at any depth
 * of the argument arrays; objects are not opened (test helper). `json_encode()` of a trace
 * cannot answer this: inside a run the trace holds objects it refuses, and it returns false.
 *
 * @param array<int|string,mixed> $values A trace (`getTrace()`), or one level of it.
 */
function trace_holds (array $values, string $needle): bool
{
   // @@
   foreach ($values as $value) {
      if (is_string($value) === true && str_contains($value, $needle) === true) {
         return true;
      }
      if (is_array($value) === true && trace_holds($value, $needle) === true) {
         return true;
      }
   }

   // :
   return false;
}

/**
 * Open a readable in-memory stream seeded with the given contents (test helper).
 *
 * @return resource
 */
function source (string $contents)
{
   $Stream = fopen('php://temp', 'r+');
   fwrite($Stream, $contents);
   rewind($Stream);

   return $Stream;
}

/**
 * Read a path fully into memory through the streaming read(); false when missing (test helper).
 */
function grab (Storage|Driver $Disk, string $path): string|false
{
   $Stream = fopen('php://temp', 'r+');
   // ?
   if ($Disk->read($path, $Stream) === false) {
      return false;
   }
   rewind($Stream);

   // :
   return stream_get_contents($Stream);
}

/**
 * Every entry under a directory, relative and sorted: a directory ends in `/`, a symlink in
 * `@` and is never followed (test helper).
 *
 * @return array<int,string>
 */
function scan (string $dir): array
{
   $entries = [];
   // ?
   if (is_dir($dir) === false) {
      return $entries;
   }

   // @
   $Iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
   );
   foreach ($Iterator as $Info) {
      $relative = substr($Info->getPathname(), strlen($dir) + 1);
      $mark = $Info->isLink() === true ? '@' : ($Info->isDir() === true ? '/' : '');
      $entries[] = "{$relative}{$mark}";
   }
   sort($entries);

   // :
   return $entries;
}

/**
 * Remove a tree, unlinking every symlink instead of following it (test helper).
 */
function wipe (string $path): void
{
   // ?
   if (is_link($path) === true || is_file($path) === true) {
      @unlink($path);

      return;
   }
   if (is_dir($path) === false) {
      return;
   }

   // @@
   foreach (scandir($path) ?: [] as $entry) {
      if ($entry !== '.' && $entry !== '..') {
         wipe("{$path}/{$entry}");
      }
   }
   @rmdir($path);
}

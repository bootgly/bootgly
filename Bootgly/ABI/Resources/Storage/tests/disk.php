<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function fopen;
use function fwrite;
use function getenv;
use function rewind;
use function stream_get_contents;

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

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function assert;
use function fopen;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use function trace_holds;
use InvalidArgumentException;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ACI\Tests\Suite\Test;


require_once __DIR__ . '/disk.php';


/**
 * The S3 driver with its signer and its multipart handle, with no endpoint at all: what the
 * constructor accepts and refuses, the handle on its own, and a disk whose bucket cannot be
 * put in a canonical request — every operation fails with a reason, none throws.
 */
return new Test(
   description: 'Storage(S3) offline: constructor refusals, the Uploads handle alone, an unsignable disk',
   test: function () {
      // # A disk without credentials still builds (public buckets, credentials set later by config)
      $Anonymous = new S3('', ['bucket' => 'examplebucket']);
      yield assert(
         assertion: $Anonymous->Uploads instanceof Uploads
            && $Anonymous->presign('GET', 'a.txt', 60) === false
            && $Anonymous->error === 'S3 presign: the disk has no credentials (key, secret)',
         description: 'a disk with no key and no secret builds, and refuses to presign'
      );

      // # Credentials that could never authenticate are refused at construction
      $message = 'S3 Signer: the key, region, service and token must be printable ASCII without spaces.';
      $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
      $refused = true;
      $leaked = false;
      $ignored = ini_get('zend.exception_ignore_args');
      ini_set('zend.exception_ignore_args', '0');
      try {
         foreach ([
            ['key' => 'AKIA EXAMPLE'],
            ['key' => "AKIAEXAMPLE\n"],
            ['region' => "us-east-1\r\nx-amz-acl: public-read"],
            ['service' => 's3 '],
            ['token' => "token\nX-Injected: 1"],
         ] as $options) {
            try {
               new S3('', $options + ['bucket' => 'examplebucket', 'key' => 'AKIAEXAMPLE', 'secret' => $secret]);
               $refused = false;
            }
            catch (InvalidArgumentException $Exception) {
               $refused = $refused && $Exception->getMessage() === $message;
               $leaked = $leaked
                  || str_contains($Exception->getTraceAsString(), $secret) === true
                  || trace_holds($Exception->getTrace(), 'bPxRfiCYEXAMPLEKEY') === true;
            }
         }
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignored);
      }
      yield assert(
         assertion: $refused,
         description: 'a key, region, service or token with a space or a line break is refused by the constructor'
      );
      yield assert(
         assertion: $leaked === false,
         description: 'the constructor\'s stack trace does not carry the disk options (the secret)'
      );

      // # A dump shows where the disk points, never what it signs with
      $Disk = new S3('media', ['bucket' => 'examplebucket', 'key' => 'AKIAEXAMPLE', 'secret' => $secret, 'token' => "session-{$secret}"]);
      $Signer = new Signer('AKIAEXAMPLE', $secret, 'eu-west-1', 's3', "session-{$secret}");
      $Signer->presign('GET', 'examplebucket.s3.amazonaws.com', '/k', [], 60);
      $dumped = print_r($Disk, true) . print_r($Disk->Uploads, true) . print_r($Signer, true) . print_r([$Disk, $Signer], true);
      yield assert(
         assertion: str_contains($dumped, 'bPxRfiCYEXAMPLEKEY') === false
            && str_contains(print_r($Disk, true), '[bucket] => examplebucket') === true
            && str_contains(print_r($Disk, true), '[endpoint] => https://examplebucket.s3.us-east-1.amazonaws.com') === true
            && str_contains(print_r($Signer, true), '[key] => AKIAEXAMPLE') === true
            && str_contains(print_r($Signer, true), '[region] => eu-west-1') === true,
         description: 'print_r() of the disk, of its Uploads handle and of a Signer shows the bucket, the endpoint and the scope — never the secret, the token or the signing key'
      );

      // # The transport rule is what it was
      $insecure = '';
      try {
         new S3('', ['bucket' => 'examplebucket', 'endpoint' => 'http://127.0.0.1:9000']);
      }
      catch (InvalidArgumentException $Exception) {
         $insecure = $Exception->getMessage();
      }
      yield assert(
         assertion: $insecure === "S3 insecure transport (http or verify => false) requires the 'insecure' => true option.",
         description: 'an http endpoint still needs `insecure => true`'
      );

      // # The handle alone: each method is its step, arguments and result untouched
      $Calls = [];
      $Uploads = new Uploads(
         Create: static function (string $path, array $options) use (&$Calls): string {
            $Calls[] = ['create', $path, $options];

            return 'upload-id';
         },
         Sign: static function (string $path, string $id, int $part, int $expires) use (&$Calls): string {
            $Calls[] = ['sign', $path, $id, $part, $expires];

            return 'https://example.com/part';
         },
         List: static function (string $path, string $id) use (&$Calls): array {
            $Calls[] = ['list', $path, $id];

            return [1 => ['etag' => '"a"', 'size' => 5]];
         },
         Complete: static function (string $path, string $id, array $parts) use (&$Calls): bool {
            $Calls[] = ['complete', $path, $id, $parts];

            return true;
         },
         Abort: static function (string $path, string $id) use (&$Calls): bool {
            $Calls[] = ['abort', $path, $id];

            return false;
         }
      );
      yield assert(
         assertion: $Uploads->create('a.bin', ['type' => 'video/mp4']) === 'upload-id'
            && $Uploads->sign('a.bin', 'upload-id', 2, 60) === 'https://example.com/part'
            && $Uploads->list('a.bin', 'upload-id') === [1 => ['etag' => '"a"', 'size' => 5]]
            && $Uploads->complete('a.bin', 'upload-id', [1 => ['etag' => '"a"']]) === true
            && $Uploads->abort('a.bin', 'upload-id') === false
            && $Calls === [
               ['create', 'a.bin', ['type' => 'video/mp4']],
               ['sign', 'a.bin', 'upload-id', 2, 60],
               ['list', 'a.bin', 'upload-id'],
               ['complete', 'a.bin', 'upload-id', [1 => ['etag' => '"a"']]],
               ['abort', 'a.bin', 'upload-id'],
            ],
         description: 'create(), sign(), list(), complete() and abort() each run their own step with the arguments as given'
      );

      // # A disk whose bucket cannot be signed: a reason, never an exception, never a request
      $Unsignable = new S3('', [
         'bucket' => 'my bucket',
         'key' => 'AKIAEXAMPLE',
         'secret' => $secret,
         'endpoint' => 'https://127.0.0.1:1',
      ]);
      $Sink = fopen('php://memory', 'r+');
      yield assert(
         assertion: $Unsignable->presign('GET', 'a.txt', 60) === false
            && $Unsignable->error === 'S3 presign: the request cannot be signed (check the bucket and the endpoint)'
            && $Unsignable->Uploads->sign('a.txt', 'id', 1, 60) === false
            && $Unsignable->error === 'S3 presign: the request cannot be signed (check the bucket and the endpoint)'
            && $Unsignable->Uploads->create('a.txt') === false
            && $Unsignable->error === 'S3 create multipart /my bucket/a.txt: HTTP 0'
            && $Unsignable->Uploads->list('a.txt', 'id') === false
            && $Unsignable->Uploads->complete('a.txt', 'id', [1 => ['etag' => 'a']]) === false
            && $Unsignable->Uploads->abort('a.txt', 'id') === false
            && $Unsignable->check('a.txt') === false
            && $Unsignable->read('a.txt', $Sink, 0, 5) === false
            && $Unsignable->delete('a.txt') === false
            && str_contains($Unsignable->error, $secret) === false,
         description: 'a bucket that cannot be put in a canonical request fails every operation with a reason'
      );
   }
);

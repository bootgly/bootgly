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
use function count;
use function ini_get;
use function ini_set;
use function str_contains;
use function trace_holds;
use InvalidArgumentException;
use ReflectionParameter;
use SensitiveParameter;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test;


require_once __DIR__ . '/disk.php';


/**
 * A disk that its driver refuses to build throws from under the frames that carry the disk
 * definition — the facade and the driver registry. With `zend.exception_ignore_args=0`
 * (the development and CI setting) a stack trace holds every argument of every frame, so
 * none of those frames may hold the options: they are where the S3 secret lives.
 */
return new Test(
   description: 'Storage: the frames that build a disk keep its options (the S3 secret) out of a stack trace',
   test: function () {
      $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
      // ! A key with a space: the S3 driver refuses it at construction
      $disk = ['driver' => 's3', 'bucket' => 'examplebucket', 'key' => 'AKIA EXAMPLE', 'secret' => $secret];
      $message = 'S3 Signer: the key, region, service and token must be printable ASCII without spaces.';

      $ignored = ini_get('zend.exception_ignore_args');
      ini_set('zend.exception_ignore_args', '0');
      try {
         // # Through the facade
         $Storage = new Storage(['default' => 'remote', 'disks' => ['remote' => $disk]]);
         $refused = '';
         $frames = 0;
         $leaked = true;
         try {
            $Storage->open();
         }
         catch (InvalidArgumentException $Exception) {
            $refused = $Exception->getMessage();
            $frames = count($Exception->getTrace());
            $leaked = trace_holds($Exception->getTrace(), 'bPxRfiCYEXAMPLEKEY') === true
               || str_contains($Exception->getTraceAsString(), 'bPxRfiCYEXAMPLEKEY') === true;
         }
         yield assert(
            assertion: $refused === $message && $frames >= 3,
            description: 'the facade lets the driver\'s refusal through, with the frames that built the disk'
         );
         yield assert(
            assertion: $leaked === false,
            description: 'the trace of a disk refused through the facade does not carry the secret'
         );

         // # Through the registry alone
         $leaked = true;
         try {
            (new Drivers)->build('s3', '', ['bucket' => 'examplebucket', 'key' => 'AKIA EXAMPLE', 'secret' => $secret]);
         }
         catch (InvalidArgumentException $Exception) {
            $leaked = trace_holds($Exception->getTrace(), 'bPxRfiCYEXAMPLEKEY') === true;
         }
         yield assert(
            assertion: $leaked === false,
            description: 'the trace of a disk refused by Drivers::build() does not carry the secret'
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignored);
      }

      // # The configuration frames, which nothing under them throws from today
      $sensitive = static fn (string $class, string $parameter): bool => (new ReflectionParameter([$class, '__construct'], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $sensitive(Storage::class, 'config') === true && $sensitive(Config::class, 'config') === true,
         description: 'the Storage and Storage\Config constructors mark the configuration as sensitive'
      );
   }
);

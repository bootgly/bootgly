<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\S3;


use function assert;
use function count;
use function fake_skip;
use function print_r;
use function str_contains;
use function strlen;
use function var_export;
use function wipe;
use ReflectionProperty;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;


require_once __DIR__ . '/s3.php';


/**
 * What a dump shows while an S3 request is parked: the resource, its embedded client and the
 * factory `provide()` registered — never the secret key, the session token or the signature.
 */
return new Test(
   description: 'Resources: a dump of the S3 resource, its client or its factory while a request is parked shows no credential',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('wpi-s3-secrets');
      $Fake = Fake::start($base);
      $Rig = mount();
      $MiB = 1024 * 1024;
      $token = 'FAKE-SESSION-TOKEN-0123456789abcdef';

      try {
         $options = $Fake->configure(['token' => $token]);
         $S3 = new S3(new Writer('', $options), part: 5 * $MiB, budget: 10 * $MiB, timeout: 10, connectTimeout: 2);
         $S3->schedule($Rig->park);

         // ! A request held by the store: the signed headers sit in the client's pending request
         $Fake->delay('PutObject', 1.0);
         $Job = launch(static fn (): bool => $S3->write('secret.txt', 'payload'));
         spin($Rig, [$Job], 0.3);
         $parked = $Job->Fiber->isSuspended();

         $Client = (new ReflectionProperty(S3::class, 'Client'))->getValue($S3);
         $Factory = S3::provide(new Storage(['default' => 'files', 'disks' => ['files' => ['driver' => 's3'] + $options]]), part: 5 * $MiB, budget: 10 * $MiB);
         $Dumps = [
            'resource' => print_r($S3, true),
            'client' => print_r($Client, true),
            'factory' => print_r($Factory, true),
         ];

         spin($Rig, [$Job], 5.0);

         $leaks = [];
         foreach ($Dumps as $name => $dump) {
            foreach ([Fake::SECRET, $token, 'Signature=', 'X-Amz-Signature'] as $secret) {
               if (str_contains($dump, $secret) === true) {
                  $leaks[] = "{$name}: {$secret}";
               }
            }
         }
         $log = $Fake->log();
         $sent = $log[count($log) - 1] ?? [];
         yield assert(
            assertion: $parked === true
               && $leaks === []
               && str_contains($Dumps['client'], 'x-amz-security-token') === true
               && strlen($Dumps['client']) > 0
               && ($sent['headers']['x-amz-security-token'] ?? '') === $token
               && $Job->result === true,
            description: 'The token travels signed, yet no dump taken while it waits shows it, the secret or the signature, found: ' . var_export([$parked, $leaks, $Job->result], true)
         );
      }
      finally {
         ($Rig->restore)();
         $Fake->stop();
         wipe($base);
      }
   }
);

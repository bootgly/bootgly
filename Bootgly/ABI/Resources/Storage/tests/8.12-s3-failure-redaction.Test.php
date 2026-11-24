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


use function array_keys;
use function assert;
use function get_object_vars;
use function print_r;
use function str_contains;
use ReflectionClassConstant;
use ReflectionParameter;
use SensitiveParameter;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Operations;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A failure carries what a caller branches on — never the request, the credentials or the
 * store's message; the frames that carry the signed headers to the socket keep them out of a
 * trace; the reader and the uploads agree on the highest part number.
 */
return new Test(
   description: 'Storage(S3) offline: a Failure holds no request bytes nor credentials; the transport frames mark the signed headers',
   test: function () {
      $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
      $token = 'FQoGZXIvYXdzEXAMPLETOKEN';
      $S3 = new S3('', [
         'bucket' => 'my bucket',
         'key' => 'AKIAIOSFODNN7EXAMPLE',
         'secret' => $secret,
         'token' => $token,
         'endpoint' => 'https://127.0.0.1:1',
      ]);

      // # A failure of a request that was never sent
      $S3->check('a.txt');
      $Failure = $S3->Failure;
      $Dump = print_r($Failure, true);
      yield assert(
         assertion: $Failure instanceof Failure
            && array_keys(get_object_vars($Failure)) === ['status', 'code', 'kind', 'message']
            && str_contains($Dump, $secret) === false
            && str_contains($Dump, $token) === false
            && str_contains($Dump, 'AKIAIOSFODNN7EXAMPLE') === false
            && str_contains($S3->error, $secret) === false,
         description: 'a Failure is its status, code, kind and message — no credential, no signature'
      );

      // # The store's own message never reaches the failure
      $Echo = Reader::judge(
         Operations::PutObject,
         403,
         [],
         "<Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match</Message><StringToSign>AWS4-HMAC-SHA256 {$token}</StringToSign></Error>",
         'S3 PUT /b/k'
      );
      yield assert(
         assertion: $Echo?->code === 'SignatureDoesNotMatch'
            && $Echo->message === 'S3 PUT /b/k: HTTP 403'
            && str_contains(print_r($Echo, true), $token) === false
            && str_contains(print_r($Echo, true), 'calculated') === false,
         description: 'an error body that echoes the signed request leaves only its code on the failure'
      );

      // # The frames that hand the signed headers to the socket
      $sensitive = static fn (string $class, string $method, string $parameter): bool => (new ReflectionParameter([$class, $method], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $sensitive(S3::class, 'transmit', 'headers') === true
            && $sensitive(S3::class, 'send', 'headers') === true
            && $sensitive(Writer::class, '__construct', 'options') === true,
         description: 'transmit(), send() and the Writer constructor mark what carries a credential as sensitive'
      );

      // # One highest part number
      yield assert(
         assertion: (new ReflectionClassConstant(Reader::class, 'PARTS'))->getValue() === Uploads::PARTS,
         description: 'the reader checks part numbers against the same bound as the uploads'
      );
   }
);

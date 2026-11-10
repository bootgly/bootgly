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
use function hash;
use function hash_hmac;
use InvalidArgumentException;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Signer::presign()` with signed headers: the headers a presigned request is required to
 * send join `Host` in the canonical headers and in `X-Amz-SignedHeaders`. The vectors come
 * from the AWS SDK for Python at a frozen clock, and the chain is written out once here,
 * independent of the class.
 */
return new Test(
   description: 'Storage(S3) Signer: a presigned URL signs the headers its request must send (SDK vectors)',
   test: function () {
      $key = 'R2TESTACCESSKEYEXAMPLE';
      $secret = 'r2/test+secret/EXAMPLEKEY0123456789abcd';
      $Signer = new Signer($key, $secret, 'auto');
      $account = '0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com';
      $path = '/videos/media/7/a%20b%2Bc.mp4';
      $now = 1794141000; // 20261108T123000Z
      $prefix = 'X-Amz-Algorithm=AWS4-HMAC-SHA256'
         . '&X-Amz-Credential=R2TESTACCESSKEYEXAMPLE%2F20261108%2Fauto%2Fs3%2Faws4_request'
         . '&X-Amz-Date=20261108T123000Z';

      // # An object PUT bound to its type, size, bytes and metadata
      $headers = [
         'Content-Type' => 'video/mp4',
         'Content-Length' => '1048576',
         'Content-MD5' => '1B2M2Y8AsgTpgAmY7PhCfg==',
         'x-amz-meta-Owner' => '  seven   of nine ',
      ];
      $bound = $Signer->presign('PUT', $account, $path, [], 600, $headers, $now);

      // ! The HMAC chain written out, independent of the class
      $canonical = "{$prefix}&X-Amz-Expires=600"
         . '&X-Amz-SignedHeaders=content-length%3Bcontent-md5%3Bcontent-type%3Bhost%3Bx-amz-meta-owner';
      $request = "PUT\n{$path}\n{$canonical}\n"
         . "content-length:1048576\ncontent-md5:1B2M2Y8AsgTpgAmY7PhCfg==\ncontent-type:video/mp4\n"
         . "host:{$account}\nx-amz-meta-owner:seven of nine\n\n"
         . "content-length;content-md5;content-type;host;x-amz-meta-owner\nUNSIGNED-PAYLOAD";
      $derived = hash_hmac('sha256', '20261108', "AWS4{$secret}", true);
      $derived = hash_hmac('sha256', 'auto', $derived, true);
      $derived = hash_hmac('sha256', 's3', $derived, true);
      $derived = hash_hmac('sha256', 'aws4_request', $derived, true);
      $signature = hash_hmac(
         'sha256',
         "AWS4-HMAC-SHA256\n20261108T123000Z\n20261108/auto/s3/aws4_request\n" . hash('sha256', $request),
         $derived
      );
      yield assert(
         assertion: $bound === "{$canonical}&X-Amz-Signature=ee0e45c33e3894f34d777afbf55594ab1c64cb2489e58d8b578e31ca9f20aac3"
            && $signature === 'ee0e45c33e3894f34d777afbf55594ab1c64cb2489e58d8b578e31ca9f20aac3',
         description: 'presigned PUT with signed headers: lowercase names sorted with host, folded values; the SDK and the written-out chain agree'
      );

      // # A part PUT bound to its size
      yield assert(
         assertion: $Signer->presign('PUT', $account, $path, ['partNumber' => 2, 'uploadId' => 'AbC~1/2+3=='], 3600, ['Content-Length' => '8388608'], $now)
            === "{$prefix}&X-Amz-Expires=3600&X-Amz-SignedHeaders=content-length%3Bhost"
               . '&partNumber=2&uploadId=AbC~1%2F2%2B3%3D%3D'
               . '&X-Amz-Signature=53a6c46a85dff626f776c5d354e2d882ed4d5cdb412a3b3bfbd350468b232519',
         description: 'presigned part PUT with a signed Content-Length (SDK vector)'
      );

      // # No header, or only the ones the signer owns: the URL of before
      $plain = $Signer->presign('PUT', $account, $path, [], 600, time: $now);
      yield assert(
         assertion: $Signer->presign('PUT', $account, $path, [], 600, [], $now) === $plain
            && $Signer->presign('PUT', $account, $path, [], 600, ['Host' => 'evil.example', 'Authorization' => 'x'], $now) === $plain
            && $plain !== $bound,
         description: 'with no header only Host is signed; a Host or an Authorization passed as a header never replaces the signer\'s'
      );

      // # Each value is part of the signature
      $other = $headers;
      $other['Content-Length'] = '1048577';
      yield assert(
         assertion: $Signer->presign('PUT', $account, $path, [], 600, $other, $now) !== $bound
            && $Signer->presign('PUT', $account, $path, [], 600, ['content-type' => 'video/mp4', 'CONTENT-LENGTH' => '1048576', 'content-md5' => '1B2M2Y8AsgTpgAmY7PhCfg==', 'X-Amz-Meta-Owner' => 'seven of nine'], $now) === $bound,
         description: 'another value is another signature; the letter case of a name and the folding of a value are not'
      );

      // # A header that could break out of its line is never signed
      $refuse = static function (array $headers) use ($Signer, $account, $path, $now): string {
         try {
            $Signer->presign('PUT', $account, $path, [], 600, $headers, $now);
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };
      $illegal = 'S3 Signer: a signed header has an illegal name or a control character in its value.';
      yield assert(
         assertion: $refuse(['Content-Type' => "video/mp4\nx-amz-acl:public-read"]) === $illegal
            && $refuse(["Content-Type\nx-amz-acl" => 'video/mp4']) === $illegal
            && $refuse(['Content Type' => 'video/mp4']) === $illegal
            && $refuse(['' => 'video/mp4']) === $illegal
            && $refuse(['Content-Type' => "video/mp4\0"]) === $illegal,
         description: 'presign() refuses a header name that is not a token and a value with a control character'
      );
   }
);

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
use function substr;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `S3\Signer` against published Signature Version 4 vectors.
 *
 * The first group is the Amazon S3 documentation's own examples ("Signature Calculations
 * for the Authorization Header" and "Authenticating Requests: Using Query Parameters":
 * `AKIAIOSFODNN7EXAMPLE`, 2013-05-24T00:00:00Z, `examplebucket`, `us-east-1`) — each
 * expected signature is the one the documentation prints. The second group has the shape
 * of an S3-compatible store (region `auto`, path-style keys, a session token); its
 * expected signatures were produced by the AWS SDK's signer (botocore 1.43.122) at a
 * frozen clock, and one of them is also derived here step by step.
 */
return new Test(
   description: 'Storage(S3) Signer: the documented SigV4 vectors, header auth and query auth',
   test: function () {
      $AWS = new Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
      $host = 'examplebucket.s3.amazonaws.com';
      $time = 1369353600; // 20130524T000000Z
      $empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
      $credential = 'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request';

      // # Header auth — "Example: GET Object" (the Range header is signed)
      yield assert(
         assertion: $AWS->sign('GET', $host, '/test.txt', [], ['Range' => 'bytes=0-9'], hash('sha256', ''), $time) === [
            'Authorization' => "AWS4-HMAC-SHA256 {$credential}, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, "
               . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            'x-amz-date' => '20130524T000000Z',
            'x-amz-content-sha256' => $empty,
         ],
         description: 'GET Object: the documented Authorization, x-amz-date and payload hash'
      );

      // # Header auth — "Example: PUT Object" (an encoded `$` in the path; Date and x-amz-storage-class signed)
      $PUT = $AWS->sign(
         'PUT',
         $host,
         '/test%24file.text',
         [],
         ['Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'],
         hash('sha256', 'Welcome to Amazon S3.'),
         $time
      );
      yield assert(
         assertion: $PUT['Authorization'] === "AWS4-HMAC-SHA256 {$credential}, "
            . 'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, '
            . 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
         description: 'PUT Object: the documented signature and SignedHeaders'
      );

      // # Header auth — "Example: GET Bucket Lifecycle" and "Example: Get Bucket (List Objects)"
      $lifecycle = $AWS->sign('GET', $host, '/', ['lifecycle' => ''], [], $empty, $time);
      $list = $AWS->sign('GET', $host, '/', ['prefix' => 'J', 'max-keys' => 2], [], $empty, $time);
      yield assert(
         assertion: substr($lifecycle['Authorization'], -64) === 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543'
            && substr($list['Authorization'], -64) === '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
         description: 'query subresources: the documented lifecycle and list signatures'
      );

      // # Query auth — the documented presigned GET (86400 s)
      yield assert(
         assertion: $AWS->presign('GET', $host, '/test.txt', [], 86400, time: $time) === 'X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
         description: 'presigned GET: the documented query string, the signature last'
      );

      // # S3-compatible shape — a presigned multipart part (region auto, path-style, encoded key)
      $key = 'R2TESTACCESSKEYEXAMPLE';
      $secret = 'r2/test+secret/EXAMPLEKEY0123456789abcd';
      $Compatible = new Signer($key, $secret, 'auto');
      $account = '0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com';
      $now = 1794141000; // 20261108T123000Z
      $part = $Compatible->presign('PUT', $account, '/videos/media/7/a%20b%2Bc.mp4', [
         'partNumber' => 2,
         'uploadId' => 'AbC~1/2+3==',
      ], 3600, time: $now);

      // ! The HMAC chain written out, independent of the class
      $canonical = 'X-Amz-Algorithm=AWS4-HMAC-SHA256'
         . '&X-Amz-Credential=R2TESTACCESSKEYEXAMPLE%2F20261108%2Fauto%2Fs3%2Faws4_request'
         . '&X-Amz-Date=20261108T123000Z&X-Amz-Expires=3600&X-Amz-SignedHeaders=host'
         . '&partNumber=2&uploadId=AbC~1%2F2%2B3%3D%3D';
      $request = "PUT\n/videos/media/7/a%20b%2Bc.mp4\n{$canonical}\nhost:{$account}\n\nhost\nUNSIGNED-PAYLOAD";
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
         assertion: $part === "{$canonical}&X-Amz-Signature=31e93a159518b9330bccc873d4739d1c2b76a08e3b6cc60b189161b7616cd3e6"
            && $signature === '31e93a159518b9330bccc873d4739d1c2b76a08e3b6cc60b189161b7616cd3e6',
         description: 'presigned part PUT: X-Amz-* sort before partNumber/uploadId; the SDK and the written-out chain agree'
      );

      // # S3-compatible shape — a session token: sent and signed, in both forms
      $Session = new Signer($key, $secret, 'auto', 's3', 'FQoGZXIvYXdzEXAMPLETOKEN');
      $download = $Session->presign('GET', $account, '/videos/media/7/a%20b%2Bc.mp4', [
         'response-content-disposition' => 'attachment; filename="clip one.mp4"',
      ], 900, time: $now);
      yield assert(
         assertion: $download === 'X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=R2TESTACCESSKEYEXAMPLE%2F20261108%2Fauto%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20261108T123000Z&X-Amz-Expires=900'
            . '&X-Amz-Security-Token=FQoGZXIvYXdzEXAMPLETOKEN&X-Amz-SignedHeaders=host'
            . '&response-content-disposition=attachment%3B%20filename%3D%22clip%20one.mp4%22'
            . '&X-Amz-Signature=5f1ca8a7e6e432ce3c3f411868ed605b28324e2c969a9eb11513b37faa7ec6e4',
         description: 'presigned GET with a session token and a response override (SDK vector)'
      );
      $poster = $Session->sign(
         'PUT',
         '127.0.0.1:8111',
         '/test-videos/videos/7/x.poster.jpg',
         [],
         ['Content-Type' => 'image/jpeg'],
         hash('sha256', 'JPEGBYTES'),
         $now
      );
      yield assert(
         assertion: $poster === [
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261108/auto/s3/aws4_request, '
               . 'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-security-token, '
               . 'Signature=34ba37f761ff5d577608f39bcb533d67202c4df6cc1fd1b840fa06861f675cf5',
            'x-amz-date' => '20261108T123000Z',
            'x-amz-content-sha256' => 'bddcd3dfa0684499eb00f0dcd919032f4d4e4e15f815752618c4de324f6b1803',
            'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
         ],
         description: 'header auth with a session token, a Content-Type and a host with a port (SDK vector)'
      );

      // # S3-compatible shape — `?uploads` and a Range
      $create = $Compatible->sign('POST', $account, '/videos/media/7/a.mp4', ['uploads' => ''], ['Content-Type' => 'video/mp4'], $empty, $now);
      $range = $Compatible->sign('GET', $account, '/videos/media/7/a.mp4', [], ['Range' => 'bytes=100-199'], $empty, $now);
      yield assert(
         assertion: substr($create['Authorization'], -64) === 'd3ead06b1f4d1bc3575a001e1e36441e20aa4d60925a240608e4a1dd9679f305'
            && substr($range['Authorization'], -64) === 'e5d78a0304b11e044bc0e2bd5e922d8b86e4bbb3886ce03f24b4c471255222aa',
         description: '`?uploads` signs as `uploads=` and a Range is bound to the signature (SDK vectors)'
      );

      // # Canonical forms
      yield assert(
         assertion: $Compatible->sign('GET', $account, '/b/k', [], [
            'X-Custom' => "  a \t  b ",
            'Host' => 'evil.example',
            'Authorization' => 'x',
            'x-amz-date' => 'forged',
         ], $empty, $now) === $Compatible->sign('GET', $account, '/b/k', [], ['x-custom' => 'a b'], $empty, $now),
         description: 'header names fold case and values fold white space; a caller\'s Host, Authorization and x-amz-date never reach the signature'
      );
      yield assert(
         assertion: Signer::encode(['uploads' => '']) === 'uploads='
            && Signer::encode(['b' => 'x y', 'B' => '~/+=', 'a' => 1, 'é' => 'ü', 10 => 'n']) === '%C3%A9=%C3%BC&10=n&B=~%2F%2B%3D&a=1&b=x%20y'
            && Signer::encode([]) === '',
         description: 'encode(): RFC 3986 escapes, `~` kept, byte-order sort of the encoded names, empty values keep `=`'
      );

      // # The signing key is derived once per day — and re-derived when the day changes
      $Fresh = new Signer($key, $secret, 'auto');
      $first = $Compatible->sign('GET', $account, '/b/k', [], [], $empty, $now);
      $next = $Compatible->sign('GET', $account, '/b/k', [], [], $empty, $now + 86400);
      $again = $Compatible->sign('GET', $account, '/b/k', [], [], $empty, $now);
      yield assert(
         assertion: $first === $again
            && $first !== $next
            && $next === $Fresh->sign('GET', $account, '/b/k', [], [], $empty, $now + 86400),
         description: 'a signer reused across days signs each day with that day\'s key'
      );
   }
);

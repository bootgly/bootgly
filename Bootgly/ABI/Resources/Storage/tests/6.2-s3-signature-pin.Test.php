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
use ReflectionMethod;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The request line and headers the driver signs for its own requests, at fixed clocks. The
 * expected values were recorded from the driver before its signing moved into `S3\Signer`:
 * the delegation must not change one byte of what goes on the wire — header order included.
 */
return new Test(
   description: 'Storage(S3) offline: the signed request line and headers are byte-identical to the recorded ones',
   test: function () {
      $AWS = new S3('', [
         'bucket' => 'examplebucket',
         'region' => 'us-east-1',
         'key' => 'AKIAIOSFODNN7EXAMPLE',
         'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
      ]);
      $Compatible = new S3('media/library', [
         'bucket' => 'videos',
         'region' => 'auto',
         'key' => 'R2TESTACCESSKEYEXAMPLE',
         'secret' => 'r2/test+secret/EXAMPLEKEY0123456789abcd',
         'token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
         'endpoint' => 'http://127.0.0.1:9000',
         'insecure' => true,
      ]);

      // ! [driver, method, path, query, body, extra headers, time]
      $requests = [
         'get' => [$AWS, 'GET', '/test.txt', [], '', [], 1369353600],
         'put-type-meta' => [$AWS, 'PUT', '/a%20b/c%2Bd.txt', [], 'Welcome to Amazon S3.', [
            'Content-Type' => 'text/plain',
            'x-amz-meta-owner' => 'rodrigo',
            'x-amz-meta-b' => '2',
         ], 1369353600],
         'copy' => [$AWS, 'PUT', '/dst', [], '', ['x-amz-copy-source' => '/examplebucket/src%20file'], 1369353600],
         'list' => [$Compatible, 'GET', '/videos', [
            'list-type' => '2',
            'prefix' => 'media/library/a b/c+d=',
            'delimiter' => '/',
            'continuation-token' => 'tok/en+1==',
         ], '', [], 1794141000],
         'create' => [$Compatible, 'POST', '/videos/media/library/big.bin', ['uploads' => ''], '', [
            'Content-Type' => 'video/mp4',
            'x-amz-meta-title' => 'Clip',
         ], 1794141000],
         'part' => [$Compatible, 'PUT', '/videos/media/library/big.bin', [
            'partNumber' => '3',
            'uploadId' => 'AbC~1/2+3==',
         ], 'PARTBYTES', [], 1794141000],
         'complete' => [$Compatible, 'POST', '/videos/media/library/big.bin', [
            'uploadId' => 'AbC~1/2+3==',
         ], '<CompleteMultipartUpload></CompleteMultipartUpload>', ['Content-Type' => 'application/xml'], 1794141000],
         'delete' => [$Compatible, 'DELETE', '/videos/media/library/big.bin', ['uploadId' => 'AbC~1/2+3=='], '', [], 1794227400],
      ];

      // ! Recorded before the extraction: [request line target, headers in wire order]
      $recorded = [
         'get' => [
            '/test.txt',
            [
               'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
               'x-amz-date' => '20130524T000000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=2e46714501b0d9bc603dc14b792d5c58689e101d7de843b268d12fa638eb4bda',
            ],
         ],
         'put-type-meta' => [
            '/a%20b/c%2Bd.txt',
            [
               'x-amz-content-sha256' => '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072',
               'x-amz-date' => '20130524T000000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-meta-b;x-amz-meta-owner, Signature=cb5b5ef2ee0897282cc3dec52e17893ed4563482fb2292b1b56026c8839f222a',
               'Content-Type' => 'text/plain',
               'x-amz-meta-owner' => 'rodrigo',
               'x-amz-meta-b' => '2',
            ],
         ],
         'copy' => [
            '/dst',
            [
               'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
               'x-amz-date' => '20130524T000000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-copy-source;x-amz-date, Signature=2db28e5ea4854071e9665165548c463087f054cb69e5baf07d2b43e417ecc41f',
               'x-amz-copy-source' => '/examplebucket/src%20file',
            ],
         ],
         'list' => [
            '/videos?continuation-token=tok%2Fen%2B1%3D%3D&delimiter=%2F&list-type=2&prefix=media%2Flibrary%2Fa%20b%2Fc%2Bd%3D',
            [
               'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
               'x-amz-date' => '20261108T123000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261108/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, Signature=1d3d9ed53103aa9d1595c4297700c178106c6fb0fcdbe1d4cb6f49891145ab6e',
               'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
            ],
         ],
         'create' => [
            '/videos/media/library/big.bin?uploads=',
            [
               'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
               'x-amz-date' => '20261108T123000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261108/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-meta-title;x-amz-security-token, Signature=0f7fe942802c4277704b9be58c50ce88ddb0b90d22ac3052a35f0be47933ea10',
               'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
               'Content-Type' => 'video/mp4',
               'x-amz-meta-title' => 'Clip',
            ],
         ],
         'part' => [
            '/videos/media/library/big.bin?partNumber=3&uploadId=AbC~1%2F2%2B3%3D%3D',
            [
               'x-amz-content-sha256' => 'aceab2d298a7dd895f4a04cf55b125cd750cbca9c577b36030d54f8d4ae12125',
               'x-amz-date' => '20261108T123000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261108/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, Signature=81d480abae9ffefc101116f86def3d9e96e123e13e10455a8dd2e3ec6f14b9ef',
               'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
            ],
         ],
         'complete' => [
            '/videos/media/library/big.bin?uploadId=AbC~1%2F2%2B3%3D%3D',
            [
               'x-amz-content-sha256' => '6e842b84ed8c34f3a66be9ed68a3e53471da531989f7efd4dbaf7bc54b0e1f31',
               'x-amz-date' => '20261108T123000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261108/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, Signature=16bc54f416d2bb958db48ff1f1e050f7206e7413445b94723a0693ceacf28471',
               'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
               'Content-Type' => 'application/xml',
            ],
         ],
         'delete' => [
            '/videos/media/library/big.bin?uploadId=AbC~1%2F2%2B3%3D%3D',
            [
               'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
               'x-amz-date' => '20261109T123000Z',
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=R2TESTACCESSKEYEXAMPLE/20261109/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, Signature=9982f31dd248d7aa80518454ed603a3c7bf1d8f85cb21f56446c927ff7627ffe',
               'x-amz-security-token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
            ],
         ],
      ];

      $Sign = new ReflectionMethod(S3::class, 'sign');
      foreach ($requests as $name => [$S3, $method, $path, $query, $body, $extra, $time]) {
         yield assert(
            assertion: $Sign->invoke($S3, $method, $path, $query, $body, $extra, $time) === $recorded[$name],
            description: "sign() of the `{$name}` request is byte-identical to the recorded one"
         );
      }
   }
);

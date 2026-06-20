<?php

use function hash;
use function hash_hmac;
use function str_contains;
use ReflectionMethod;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Storage(S3) offline: SigV4 signing, chunk decoding and response parsing (no network)',
   test: function () {
      $S3 = new S3('', [
         'bucket' => 'examplebucket',
         'region' => 'us-east-1',
         'key'    => 'AKIAIOSFODNN7EXAMPLE',
         'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
      ]);

      // # SigV4 — deterministic signature at a fixed date (2013-05-24T00:00:00Z)
      $sign = new ReflectionMethod($S3, 'sign');
      $sign->setAccessible(true);
      /** @var array{0:string,1:array<string,string>} $signed */
      $signed = $sign->invoke($S3, 'GET', '/test.txt', [], '', [], 1369353600);
      [, $headers] = $signed;

      // @ Re-derive the expected Authorization independently from the AWS SigV4 spec
      $amzDate = '20130524T000000Z';
      $dateStamp = '20130524';
      $payloadHash = hash('sha256', '');
      $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
      $canonicalHeaders = "host:examplebucket.s3.us-east-1.amazonaws.com\n"
         . "x-amz-content-sha256:{$payloadHash}\n"
         . "x-amz-date:{$amzDate}\n";
      $canonicalRequest = "GET\n/test.txt\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
      $scope = "{$dateStamp}/us-east-1/s3/aws4_request";
      $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
      $kDate = hash_hmac('sha256', $dateStamp, 'AWS4wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', true);
      $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
      $kService = hash_hmac('sha256', 's3', $kRegion, true);
      $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
      $signature = hash_hmac('sha256', $stringToSign, $kSigning);
      $expected = "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/{$scope}, "
         . "SignedHeaders={$signedHeaders}, Signature={$signature}";

      yield assert(
         assertion: ($headers['Authorization'] ?? '') === $expected,
         description: 'sign() builds the SigV4 Authorization header per spec'
      );
      yield assert(
         assertion: ($headers['x-amz-date'] ?? '') === $amzDate
            && ($headers['x-amz-content-sha256'] ?? '') === $payloadHash,
         description: 'sign() sets x-amz-date and the payload hash'
      );

      // @ x-amz-* extras must be folded into SignedHeaders (e.g. x-amz-copy-source)
      /** @var array{0:string,1:array<string,string>} $copy */
      $copy = $sign->invoke($S3, 'PUT', '/dst', [], '', ['x-amz-copy-source' => '/examplebucket/src'], 1369353600);
      yield assert(
         assertion: str_contains((string) $copy[1]['Authorization'], 'x-amz-copy-source'),
         description: 'sign() signs x-amz-* extra headers'
      );

      // # Chunked transfer decoding
      $dechunk = new ReflectionMethod($S3, 'dechunk');
      $dechunk->setAccessible(true);
      yield assert(
         assertion: $dechunk->invoke($S3, "5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n") === 'hello world',
         description: 'dechunk() decodes a chunked body'
      );

      // # Response parsing (status, headers, body)
      $parse = new ReflectionMethod($S3, 'parse');
      $parse->setAccessible(true);
      /** @var array{0:int,1:array<string,string|array<int,string>>,2:string} $parsed */
      $parsed = $parse->invoke($S3, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nETag: \"abc\"\r\n\r\nbody-bytes");
      yield assert(
         assertion: $parsed[0] === 200
            && ($parsed[1]['content-type'] ?? '') === 'text/plain'
            && ($parsed[1]['etag'] ?? '') === '"abc"'
            && $parsed[2] === 'body-bytes',
         description: 'parse() extracts status, headers and body'
      );
      /** @var array{0:int,1:array<string,string|array<int,string>>,2:string} $chunked */
      $chunked = $parse->invoke($S3, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n");
      yield assert(
         assertion: $chunked[2] === 'abc',
         description: 'parse() de-chunks a chunked response body'
      );
   }
);

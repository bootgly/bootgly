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


use const PHP_INT_MAX;
use function array_keys;
use function assert;
use function is_string;
use function print_r;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The request writer of an S3 disk, offline: it reads the disk's root and options as the driver
 * does and composes the same signed requests (with the `Host` they were signed for), so another
 * transport sends exactly what the driver sends; its refusals are `Failure`s, never requests.
 */
return new Test(
   description: 'Storage(S3) offline: Writer composes the requests the driver sends, and refuses what it would refuse',
   test: function () {
      $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
      $AWS = [
         'bucket' => 'examplebucket',
         'region' => 'us-east-1',
         'key' => 'AKIAIOSFODNN7EXAMPLE',
         'secret' => $secret,
      ];
      $Compatible = [
         'bucket' => 'videos',
         'region' => 'auto',
         'key' => 'R2TESTACCESSKEYEXAMPLE',
         'secret' => 'r2/test+secret/EXAMPLEKEY0123456789abcd',
         'token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
         'endpoint' => 'http://127.0.0.1:9000',
         'insecure' => true,
      ];

      // # What the writer reads from the options
      $Virtual = new Writer('', $AWS);
      $Path = new Writer('/media//library/', $Compatible);
      yield assert(
         assertion: $Virtual->host === 'examplebucket.s3.us-east-1.amazonaws.com'
            && $Virtual->authority === 'examplebucket.s3.us-east-1.amazonaws.com'
            && $Virtual->scheme === 'https'
            && $Virtual->port === 443
            && $Virtual->pathStyle === false
            && $Virtual->base === '/'
            && $Virtual->secure === ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'examplebucket.s3.us-east-1.amazonaws.com']
            && $Path->authority === '127.0.0.1:9000'
            && $Path->pathStyle === true
            && $Path->base === '/videos'
            && $Path->prefix === 'media/library'
            && $Path->region === 'auto'
            && $Path->service === 's3'
            && $Path->secure === null,
         description: 'the endpoint, the addressing style, the prefix and the TLS options are read as the driver reads them'
      );
      $Port = new Writer('', ['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'http://store.test:443', 'insecure' => true]);
      $Unverified = new Writer('', ['bucket' => 'b', 'endpoint' => 'https://store.test', 'verify' => false, 'insecure' => true]);
      yield assert(
         assertion: $Port->authority === 'store.test:443'
            && $Unverified->secure === ['verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => 'store.test'],
         description: 'an http endpoint on 443 keeps its port in the authority; verify => false reaches the TLS options'
      );
      $refused = 0;
      foreach ([['endpoint' => 'http://store.test'], ['endpoint' => 'https://store.test', 'verify' => false]] as $options) {
         try {
            new Writer('', ['bucket' => 'b'] + $options);
         }
         catch (InvalidArgumentException) {
            $refused++;
         }
      }
      yield assert(
         assertion: $refused === 2,
         description: 'an insecure transport without the insecure option is refused, as by the driver'
      );

      // # The same signed request as the driver, plus the Host it was signed for
      $Sign = new ReflectionMethod(S3::class, 'sign');
      $requests = [
         [$AWS, '', 'PUT', '/a%20b/c%2Bd.txt', [], 'Welcome to Amazon S3.', ['Content-Type' => 'text/plain', 'x-amz-meta-owner' => 'rodrigo'], 1369353600],
         [$AWS, '', 'GET', '/test.txt', [], '', ['Range' => 'bytes=0-9'], 1369353600],
         [$Compatible, 'media/library', 'PUT', '/videos/media/library/big.bin', ['partNumber' => '3', 'uploadId' => 'AbC~1/2+3=='], 'PARTBYTES', [], 1794141000],
      ];
      $same = true;
      foreach ($requests as [$options, $root, $method, $path, $query, $body, $extra, $time]) {
         $Writer = new Writer($root, $options);
         [$URI, $headers] = $Writer->compose($method, $path, $query, $body, $extra, $time);
         [$sent, $signed] = $Sign->invoke(new S3($root, $options), $method, $path, $query, $body, $extra, $time);
         $same = $same
            && $URI === $sent
            && array_keys($headers)[0] === 'Host'
            && $headers['Host'] === $Writer->authority
            && ['Host' => $Writer->authority] + $signed === $headers;
      }
      [, $Token] = (new Writer('', $Compatible))->compose('GET', '/videos/a', [], '', [], 1794141000);
      yield assert(
         assertion: $same === true && $Token['x-amz-security-token'] === 'FQoGZXIvYXdzEXAMPLETOKEN',
         description: 'compose() returns the request the driver signs, byte for byte, with the Host first'
      );
      $thrown = false;
      try {
         (new Writer('', ['bucket' => 'my bucket'] + $AWS))->compose('GET', '/a', [], '', []);
      }
      catch (InvalidArgumentException) {
         $thrown = true;
      }
      yield assert(
         assertion: $thrown === true,
         description: 'compose() of a request that cannot be put in canonical form throws instead of sending it'
      );
      $refused = 0;
      foreach ([
         ['Content-Type' => "text/plain\r\nx-amz-acl: public-read"],
         ["Content-Type\r\nX" => 'a'],
         ['Content Type' => 'a'],
         ['Content-Type' => "a\x00"],
         ['Host' => 'elsewhere.test'],
         ['host' => 'elsewhere.test'],
         ['Authorization' => 'AWS4-HMAC-SHA256 Credential=forged'],
         ['X-Amz-Date' => '20300101T000000Z'],
         ['x-amz-content-sha256' => 'UNSIGNED-PAYLOAD'],
         ['X-Amz-Security-Token' => 'forged'],
         ['Content-Length' => '0'],
         ['Transfer-Encoding' => 'chunked'],
         ['Connection' => 'keep-alive'],
         ['x-amz-meta-a' => '1', 'X-Amz-Meta-A' => '2'],
      ] as $extra) {
         try {
            $Path->compose('PUT', '/videos/a', [], '', $extra, 1794141000);
         }
         catch (InvalidArgumentException) {
            $refused++;
         }
      }
      [, $Tabbed] = $Path->compose('PUT', '/videos/a', [], '', ['Content-Type' => "text/plain;\tcharset=utf-8"], 1794141000);
      yield assert(
         assertion: $refused === 14 && $Tabbed['Content-Type'] === "text/plain;\tcharset=utf-8",
         description: 'compose() refuses a header it would send broken or twice — a line break, an illegal name, a control character, a name given twice, one the request sets itself (Host, the signature, the framing)'
      );

      // # Paths, keys and windows
      yield assert(
         assertion: $Path->resolve('') === 'media/library'
            && $Path->resolve('/a//b/../c d.txt') === 'media/library/a/c d.txt'
            && $Path->relativize('media/library/a/c d.txt') === 'a/c d.txt'
            && $Path->relativize('other/x') === 'other/x'
            && $Path->locate('a b/c+d.txt') === '/videos/media/library/a%20b/c%2Bd.txt'
            && $Path->locate('') === '/videos/media/library'
            && $Virtual->locate('a b.txt') === '/a%20b.txt'
            && $Path->target('') === null
            && $Path->target('/') === null
            && $Path->target('x.bin') === '/videos/media/library/x.bin'
            && $Path->source('a b.txt') === '/videos/media/library/a%20b.txt'
            && $Virtual->source('a b.txt') === '/examplebucket/a%20b.txt',
         description: 'resolve(), relativize(), locate(), target() and source() map a disk path to the key and the request path'
      );
      yield assert(
         assertion: $Path->range(0) === 'bytes=0-'
            && $Path->range(5, 10) === 'bytes=5-14'
            && $Path->range(-1) === null
            && $Path->range(0, 0) === null
            && $Path->range(1, PHP_INT_MAX) === null
            && $Path->range(0, PHP_INT_MAX) === 'bytes=0-' . (PHP_INT_MAX - 1),
         description: 'range() writes a window as a byte range, and refuses one that cannot be one'
      );

      // # Write options, multipart inputs and bound headers
      yield assert(
         assertion: $Path->describe([]) === ['Content-Type' => 'application/octet-stream']
            && $Path->describe(['type' => 'video/mp4', 'meta' => ['Title' => 'Clip', 'n' => 2, 'skip' => ['x']]])
               === ['Content-Type' => 'video/mp4', 'x-amz-meta-title' => 'Clip', 'x-amz-meta-n' => '2']
            && $Path->describe(['type' => "a\r\nb"]) === 'illegal newline'
            && $Path->describe(['meta' => ['a b' => 'x']]) === 'illegal character'
            && $Path->describe(['meta' => ['a' => "x\x01"]]) === 'illegal character',
         description: 'describe() turns write options into headers, and names what cannot be one'
      );
      $Invalid = $Path->validate('upload part', 'bad id');
      $Number = $Path->validate('list parts', 'id', Uploads::PARTS + 1);
      yield assert(
         assertion: $Path->validate('upload part', 'AbC~1/2+3==', 1) === null
            && $Path->validate('upload part', str_repeat('a', 1024), Uploads::PARTS) === null
            && $Path->validate('upload part', str_repeat('a', 1025)) instanceof Failure
            && $Invalid instanceof Failure
            && $Invalid->message === 'S3 upload part: invalid upload id'
            && $Invalid->status === 0
            && $Invalid->kind === Kinds::Refusal
            && $Number?->message === 'S3 list parts: the part number is 1..' . Uploads::PARTS,
         description: 'validate() refuses an upload id or a part number that may not reach a URL'
      );
      yield assert(
         assertion: $Path->bind(['Content-Type' => 'video/mp4', 'Content-Length' => 10, 'x-amz-meta-a' => 'b'], false)
               === ['content-type' => 'video/mp4', 'content-length' => '10', 'x-amz-meta-a' => 'b']
            && $Path->bind(['Content-Length' => '5', 'Content-MD5' => str_repeat('A', 22) . '=='], true)
               === ['content-length' => '5', 'content-md5' => str_repeat('A', 22) . '==']
            && $Path->bind(['Content-Type' => 'video/mp4'], true) === null
            && $Path->bind(['x-amz-meta-a' => 'b'], true) === null
            && $Path->bind(['Content-Length' => '1', 'content-length' => '1'], false) === null
            && $Path->bind(['Content-Length' => '-1'], false) === null
            && $Path->bind(['Authorization' => 'x'], false) === null,
         description: 'bind() keeps only the headers a presigned PUT may bind, each once and of its form'
      );
      $Empty = $Path->join([]);
      yield assert(
         assertion: $Path->join([2 => ['etag' => '"b"'], 1 => ['etag' => '"a&<"']])
               === '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"a&amp;&lt;"</ETag></Part>'
                  . '<Part><PartNumber>2</PartNumber><ETag>"b"</ETag></Part></CompleteMultipartUpload>'
            && $Empty instanceof Failure
            && $Empty->message === 'S3 complete multipart: 1..' . Uploads::PARTS . ' parts are required'
            && $Path->join([0 => ['etag' => 'a']]) instanceof Failure
            && $Path->join(['1' => ['etag' => '""']]) instanceof Failure
            && $Path->join([1 => ['etag' => "a\nb"]]) instanceof Failure
            && $Path->join([1 => 'a']) instanceof Failure,
         description: 'join() writes the completion in ascending order with each ETag escaped, and refuses a malformed list'
      );

      // # Presigned URLs
      $URL = $Path->presign('GET', 'a b.txt', 60, []);
      $refusals = [
         [$Path->presign('GET', 'a.txt', 0, []), 'S3 presign: the lifetime is 1..604800 seconds'],
         [(new Writer('', ['bucket' => 'b', 'endpoint' => 'http://store.test', 'insecure' => true]))->presign('GET', 'a.txt', 60, []), 'S3 presign: the disk has no credentials (key, secret)'],
         [(new Writer('', ['endpoint' => 'ftp://store.test', 'key' => 'k', 'secret' => 's', 'insecure' => true]))->presign('GET', 'a.txt', 60, []), 'S3 presign: the endpoint is not http(s)'],
         [$Path->presign('GET', '/', 60, []), 'S3 presign: an object path is required'],
         [(new Writer('', ['bucket' => 'my bucket'] + $AWS))->presign('GET', 'a.txt', 60, []), 'S3 presign: the request cannot be signed (check the bucket and the endpoint)'],
      ];
      $named = true;
      foreach ($refusals as [$Refusal, $message]) {
         $named = $named && $Refusal instanceof Failure && $Refusal->message === $message && $Refusal->kind === Kinds::Refusal;
      }
      yield assert(
         assertion: is_string($URL) === true
            && str_starts_with($URL, 'http://127.0.0.1:9000/videos/media/library/a%20b.txt?') === true
            && str_contains($URL, 'X-Amz-Expires=60') === true
            && $named === true,
         description: 'presign() returns the URL of an object, or the Refusal of the driver with its reason'
      );

      // # Credentials never leave the writer
      $Dump = print_r(new Writer('', $Compatible), true);
      $Constructor = new ReflectionParameter([Writer::class, '__construct'], 'options');
      yield assert(
         assertion: str_contains($Dump, 'r2/test+secret') === false
            && str_contains($Dump, 'FQoGZXIvYXdzEXAMPLETOKEN') === false
            && str_contains($Dump, 'R2TESTACCESSKEYEXAMPLE') === false
            && str_contains($Dump, "'endpoint' => 'http://127.0.0.1:9000'") === false
            && str_contains($Dump, '[endpoint] => http://127.0.0.1:9000') === true
            && $Constructor->getAttributes(SensitiveParameter::class) !== [],
         description: 'a dump of the writer lists where it points, never the credentials; its options are a sensitive parameter'
      );
   }
);

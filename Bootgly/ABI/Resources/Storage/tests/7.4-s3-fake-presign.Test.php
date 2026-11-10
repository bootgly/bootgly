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


use function abs;
use function assert;
use function count;
use function fake_send;
use function fake_skip;
use function gmmktime;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function time;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `S3::presign()` against the local fake, used the way its holder uses it: a bare HTTP
 * request with no credentials. What the URL authorizes is one method on one object for a
 * bounded time — nothing the holder changes (the method, the key, the lifetime, an extra
 * parameter, an `x-amz-*` header) survives the store's signature check, and nothing the
 * caller passes can turn the URL into another operation.
 */
return new Test(
   description: 'Storage(S3) fake: presign() authorizes one method on one object, bounded and jailed',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-presign');
      $Fake = Fake::start($base);

      try {
         $S3 = new S3('media/library', $Fake->configure());
         $origin = "http://127.0.0.1:{$Fake->port}";
         $Fake->seed('media/library/docs/a b/c+d.txt', '0123456789', 'text/plain');
         $Fake->seed('media/library/docs/other.txt', 'other');
         $Fake->seed('media/secret.txt', 'outside the root prefix');

         // # GET — the URL, then the request its holder sends
         $URL = $S3->presign('GET', 'docs/a b/c+d.txt', 300);
         yield assert(
            assertion: $URL !== false && $S3->error === ''
               && str_starts_with($URL, "{$origin}/test-bucket/media/library/docs/a%20b/c%2Bd.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256"
                  . '&X-Amz-Credential=' . Fake::KEY . '%2F') === true
               && preg_match('#%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=(\d{8})T(\d{6})Z&X-Amz-Expires=300&X-Amz-SignedHeaders=host&X-Amz-Signature=[0-9a-f]{64}$#D', $URL, $matches) === 1
               && abs(gmmktime(
                  (int) substr($matches[2], 0, 2),
                  (int) substr($matches[2], 2, 2),
                  (int) substr($matches[2], 4, 2),
                  (int) substr($matches[1], 4, 2),
                  (int) substr($matches[1], 6, 2),
                  (int) substr($matches[1], 0, 4)
               ) - time()) <= 5
               && str_contains($URL, Fake::SECRET) === false
               && $Fake->log() === [],
            description: 'presign() returns an absolute URL — key encoded once under the root prefix, dated now, signature last — with no request'
         );
         $URL = (string) $URL;
         [$status, , $body] = fake_send('GET', $URL);
         $Log = $Fake->log();
         yield assert(
            assertion: $status === 200 && $body === '0123456789'
               && count($Log) === 1
               && $Log[0]['auth'] === 'query'
               && $Log[0]['operation'] === 'GetObject'
               && isset($Log[0]['headers']['authorization']) === false,
            description: 'the holder reads the object with a bare GET: no credentials, accepted by the independent signature check'
         );
         [$status, $headers] = fake_send('GET', $URL, '', ['Range' => 'bytes=2-4']);
         yield assert(
            assertion: $status === 206 && ($headers['content-range'] ?? '') === 'bytes 2-4/10',
            description: 'the holder may send a Range: it is not part of what was signed'
         );

         // # What the holder cannot change
         yield assert(
            assertion: fake_send('PUT', $URL, 'overwritten')[0] === 403
               && fake_send('DELETE', $URL)[0] === 403
               && fake_send('HEAD', $URL)[0] === 403
               && $Fake->fetch('media/library/docs/a b/c+d.txt') === '0123456789',
            description: 'a GET URL does not write, delete or even HEAD: the method is signed'
         );
         yield assert(
            assertion: fake_send('GET', str_replace('/docs/a%20b/c%2Bd.txt?', '/docs/other.txt?', $URL))[0] === 403
               && fake_send('GET', str_replace('/media/library/docs/a%20b/c%2Bd.txt?', '/media/secret.txt?', $URL))[0] === 403
               && fake_send('GET', str_replace('/docs/a%20b/c%2Bd.txt?', '/docs/a%20b/../other.txt?', $URL))[0] === 403,
            description: 'the URL reads no other object, inside or outside the root prefix: the key is signed'
         );
         yield assert(
            assertion: fake_send('GET', str_replace('X-Amz-Expires=300', 'X-Amz-Expires=600', $URL))[0] === 403
               && fake_send('GET', "{$URL}&versionId=1")[0] === 403
               && fake_send('GET', str_replace('?X-Amz-Algorithm', '?uploadId=x&X-Amz-Algorithm', $URL))[0] === 403
               && fake_send('GET', str_replace('?X-Amz-Algorithm', '?response-content-type=text%2Fhtml&X-Amz-Algorithm', $URL))[0] === 403
               && fake_send('GET', substr($URL, 0, -1) . ($URL[-1] === '0' ? '1' : '0'))[0] === 403,
            description: 'a longer lifetime, an added parameter (even a response override) or an altered signature are refused: the query is signed'
         );
         yield assert(
            assertion: fake_send('GET', $URL, '', ['x-amz-request-payer' => 'requester'])[0] === 403,
            description: 'an `x-amz-*` header the URL did not sign is refused'
         );

         // # Expiry is enforced by the store, from the date in the URL
         $Signer = new Signer(Fake::KEY, Fake::SECRET, Fake::REGION);
         $path = '/test-bucket/media/library/docs/other.txt';
         $expired = "{$origin}{$path}?" . $Signer->presign('GET', "127.0.0.1:{$Fake->port}", $path, [], 60, time: time() - 3600);
         $valid = "{$origin}{$path}?" . $Signer->presign('GET', "127.0.0.1:{$Fake->port}", $path, [], 60);
         [$status, , $body] = fake_send('GET', $expired);
         [$accepted, , $bytes] = fake_send('GET', $valid);
         yield assert(
            assertion: $status === 403 && str_contains($body, 'Request has expired') === true
               && $accepted === 200 && $bytes === 'other',
            description: 'a URL past its lifetime is refused; the same signer, dated now, is accepted'
         );

         // # HEAD
         $URL = (string) $S3->presign('HEAD', 'docs/a b/c+d.txt', 60);
         [$status, $headers, $body] = fake_send('HEAD', $URL);
         yield assert(
            assertion: $status === 200 && ($headers['content-length'] ?? '') === '10' && $body === ''
               && fake_send('GET', $URL)[0] === 403,
            description: 'a HEAD URL answers the metadata, and is not a GET URL'
         );

         // # Response overrides of a GET
         $URL = (string) $S3->presign('GET', 'docs/a b/c+d.txt', 60, [
            'response-content-disposition' => 'attachment; filename="a b.txt"',
            'response-content-type' => 'application/octet-stream',
         ]);
         yield assert(
            assertion: str_contains($URL, '&response-content-disposition=attachment%3B%20filename%3D%22a%20b.txt%22'
                  . '&response-content-type=application%2Foctet-stream&X-Amz-Signature=') === true
               && fake_send('GET', $URL)[0] === 200
               && fake_send('GET', str_replace('a%20b.txt', 'evil.html', $URL))[0] === 403,
            description: 'a GET URL carries the response-* overrides the server chose, and the holder cannot alter them'
         );

         // # PUT — the holder uploads, chooses the body and the type, and nothing else
         $URL = (string) $S3->presign('PUT', 'inbox/new file.bin', 60);
         [$status] = fake_send('PUT', $URL, 'uploaded by the holder', ['Content-Type' => 'text/plain']);
         yield assert(
            assertion: $status === 200
               && $Fake->fetch('media/library/inbox/new file.bin') === 'uploaded by the holder'
               && ($Fake->describe('media/library/inbox/new file.bin')['type'] ?? '') === 'text/plain',
            description: 'a PUT URL stores the holder\'s bytes under the signed key, with the Content-Type the holder sent'
         );
         yield assert(
            assertion: fake_send('GET', $URL)[0] === 403
               && fake_send('DELETE', $URL)[0] === 403
               && fake_send('PUT', $URL, 'x', ['x-amz-acl' => 'public-read'])[0] === 403
               && fake_send('PUT', $URL, 'x', ['x-amz-copy-source' => '/test-bucket/media/secret.txt'])[0] === 403
               && fake_send('PUT', $URL, 'x', ['x-amz-meta-owner' => 'holder'])[0] === 403
               && fake_send('PUT', str_replace('/inbox/new%20file.bin?', '/docs/other.txt?', $URL), 'x')[0] === 403
               && $Fake->fetch('media/library/inbox/new file.bin') === 'uploaded by the holder'
               && $Fake->fetch('media/library/docs/other.txt') === 'other',
            description: 'a PUT URL does not read or delete, takes no ACL, copy source or metadata from the holder, and writes no other key'
         );

         // # The root prefix jail
         $before = count($Fake->log());
         yield assert(
            assertion: str_starts_with((string) $S3->presign('GET', '../../secret.txt', 60), "{$origin}/test-bucket/media/library/secret.txt?") === true
               && str_starts_with((string) $S3->presign('PUT', '/../../../etc/passwd', 60), "{$origin}/test-bucket/media/library/etc/passwd?") === true
               && str_starts_with((string) $S3->presign('GET', 'a?b#c/d', 60), "{$origin}/test-bucket/media/library/a%3Fb%23c/d?X-Amz-Algorithm=") === true,
            description: 'a path climbing above the root is presigned inside the root prefix; `?` and `#` in a key are encoded, never a query or a fragment'
         );
         $required = 'S3 presign: an object path is required';
         yield assert(
            assertion: $S3->presign('GET', '', 60) === false && $S3->error === $required
               && $S3->presign('GET', '/', 60) === false && $S3->error === $required
               && $S3->presign('PUT', '..', 60) === false && $S3->error === $required
               && $S3->presign('GET', 'docs/..', 60) === false && $S3->error === $required
               && $S3->presign('GET', '../../..', 60) === false && $S3->error === $required,
            description: 'the disk root itself is never presigned (a request on it lists the prefix)'
         );

         // # What presign() refuses to sign
         $methods = 'S3 presign: the method is GET, HEAD or PUT';
         yield assert(
            assertion: $S3->presign('DELETE', 'docs/other.txt', 60) === false && $S3->error === $methods
               && $S3->presign('POST', 'docs/other.txt', 60) === false && $S3->error === $methods
               && $S3->presign('get', 'docs/other.txt', 60) === false && $S3->error === $methods
               && $S3->presign("GET\n", 'docs/other.txt', 60) === false && $S3->error === $methods
               && $S3->presign('', 'docs/other.txt', 60) === false && $S3->error === $methods,
            description: 'only GET, HEAD and PUT are presigned: no delete, no POST, no other spelling'
         );
         $lifetime = 'S3 presign: the lifetime is 1..604800 seconds';
         yield assert(
            assertion: $S3->presign('GET', 'docs/other.txt', 0) === false && $S3->error === $lifetime
               && $S3->presign('GET', 'docs/other.txt', -60) === false && $S3->error === $lifetime
               && $S3->presign('GET', 'docs/other.txt', 604801) === false && $S3->error === $lifetime
               && $S3->presign('GET', 'docs/other.txt', 1) !== false && $S3->error === ''
               && $S3->presign('GET', 'docs/other.txt', 604800) !== false,
            description: 'the lifetime is bounded to 1..604800 seconds, both ends accepted; a success clears the error'
         );
         $overrides = 'S3 presign: only the response-* overrides of a GET or HEAD are accepted as query';
         $refused = true;
         foreach ([
            ['GET', ['uploadId' => 'x']],
            ['GET', ['partNumber' => '1']],
            ['GET', ['acl' => '']],
            ['GET', ['versionId' => '1']],
            ['GET', ['x-amz-acl' => 'public-read']],
            ['GET', ['X-Amz-Expires' => '604800']],
            ['GET', ['X-Amz-Security-Token' => 't']],
            ['GET', ['Response-Content-Type' => 'text/html']],
            ['GET', ['response-content-type' => "text/plain\r\nX: y"]],
            ['GET', ['response-content-type' => ['text/plain']]],
            ['GET', ['response-content-type' => 5]],
            ['GET', [0 => 'response-content-type']],
            ['PUT', ['response-content-type' => 'text/plain']],
            ['PUT', ['uploadId' => 'x', 'partNumber' => '1']],
            ['PUT', ['x-amz-acl' => 'public-read']],
         ] as [$method, $query]) {
            $refused = $refused && $S3->presign($method, 'docs/other.txt', 60, $query) === false && $S3->error === $overrides;
         }
         yield assert(
            assertion: $refused,
            description: 'no query parameter but the six response-* overrides of a GET/HEAD, as plain strings: no subresource, no multipart step, no x-amz-* in the query'
         );
         yield assert(
            assertion: count($Fake->log()) === $before,
            description: 'presign() never sends a request'
         );

         // # A session token is part of the URL and of the signature
         $Temporary = new S3('media/library', $Fake->configure(['token' => 'FQoGZXIvYXdzEXAMPLETOKEN']));
         $URL = (string) $Temporary->presign('GET', 'docs/other.txt', 60);
         yield assert(
            assertion: str_contains($URL, '&X-Amz-Security-Token=FQoGZXIvYXdzEXAMPLETOKEN&X-Amz-SignedHeaders=host&') === true
               && fake_send('GET', $URL)[0] === 200
               && fake_send('GET', str_replace('EXAMPLETOKEN', 'ANOTHERTOKEN', $URL))[0] === 403,
            description: 'temporary credentials: the session token travels in the URL, signed'
         );

         // # A disk that cannot sign says so
         $credentials = 'S3 presign: the disk has no credentials (key, secret)';
         $Anonymous = new S3('', $Fake->configure(['key' => '', 'secret' => '']));
         $Keyless = new S3('', $Fake->configure(['secret' => '']));
         yield assert(
            assertion: $Anonymous->presign('GET', 'a.txt', 60) === false && $Anonymous->error === $credentials
               && $Keyless->presign('PUT', 'a.txt', 60) === false && $Keyless->error === $credentials,
            description: 'a disk without a key or a secret refuses to presign instead of returning a URL that authorizes nothing'
         );

         // # The URL of a real endpoint (no request is made)
         $AWS = new S3('root', ['bucket' => 'my-bucket', 'region' => 'eu-west-1', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret']);
         $Custom = new S3('', ['bucket' => 'my-bucket', 'region' => 'auto', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret', 'endpoint' => 'https://s3.example.com:9000']);
         yield assert(
            assertion: preg_match('#^https://my-bucket\.s3\.eu-west-1\.amazonaws\.com/root/a%20b\.txt\?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAEXAMPLE%2F\d{8}%2Feu-west-1%2Fs3%2Faws4_request&#', (string) $AWS->presign('GET', 'a b.txt', 60)) === 1
               && preg_match('#^https://s3\.example\.com:9000/my-bucket/a\.txt\?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAEXAMPLE%2F\d{8}%2Fauto%2Fs3%2Faws4_request&#', (string) $Custom->presign('PUT', 'a.txt', 60)) === 1,
            description: 'AWS virtual-hosted style and a custom path-style endpoint with a port: the URL is the one the driver itself would request'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);

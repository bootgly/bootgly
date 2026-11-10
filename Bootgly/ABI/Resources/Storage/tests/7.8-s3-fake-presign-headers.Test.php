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
use function base64_encode;
use function count;
use function fake_send;
use function fake_skip;
use function gmdate;
use function hash;
use function hash_hmac;
use function md5;
use function str_contains;
use function strlen;
use function substr;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * A presigned `PUT` that binds what is uploaded, against the local fake and used the way
 * its holder uses it. Each header the server signs — the type, the size, the MD5 of the
 * bytes, the metadata — must arrive with exactly that value: the store refuses any other
 * upload, so the holder no longer chooses them. Only those headers can be bound, only on a
 * `PUT`, and a part binds its size and its bytes alone.
 */
return new Test(
   description: 'Storage(S3) fake: presign() and Uploads::sign() bind the type, size, bytes and metadata of an upload',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-presign-headers');
      $Fake = Fake::start($base);

      try {
         $S3 = new S3('media', $Fake->configure());
         $digest = static fn (string $bytes): string => base64_encode(md5($bytes, true));

         // # An object bound to its type, size, bytes and metadata
         $body = 'exactly these bytes';
         $URL = (string) $S3->presign('PUT', 'inbox/bound.txt', 60, [], [
            'Content-Type' => 'text/plain',
            'Content-Length' => strlen($body),
            'Content-MD5' => $digest($body),
            'x-amz-meta-owner' => 'seven',
         ]);
         $sent = ['Content-Type' => 'text/plain', 'Content-MD5' => $digest($body), 'x-amz-meta-owner' => 'seven'];
         yield assert(
            assertion: $S3->error === ''
               && str_contains($URL, '&X-Amz-SignedHeaders=content-length%3Bcontent-md5%3Bcontent-type%3Bhost%3Bx-amz-meta-owner&') === true
               && str_contains($URL, 'text%2Fplain') === false && str_contains($URL, 'seven') === false,
            description: 'the URL names the headers it signs, never their values'
         );

         // ! What the holder may not change — each refused before anything is stored
         $wrong = 'exactly THESE bytes';
         $refused = fake_send('PUT', $URL, $body, ['Content-Type' => 'text/html'] + $sent)[0] === 403
            && fake_send('PUT', $URL, $body, ['Content-MD5' => $digest($body), 'x-amz-meta-owner' => 'seven'])[0] === 403
            && fake_send('PUT', $URL, "{$body}, and more", $sent)[0] === 403
            && fake_send('PUT', $URL, '', $sent)[0] === 403
            && fake_send('PUT', $URL, $wrong, $sent)[0] === 400
            && fake_send('PUT', $URL, $wrong, ['Content-MD5' => $digest($wrong)] + $sent)[0] === 403
            && fake_send('PUT', $URL, $body, ['x-amz-meta-owner' => 'eight'] + $sent)[0] === 403
            && fake_send('PUT', $URL, $body, $sent + ['x-amz-meta-other' => 'x'])[0] === 403
            && fake_send('PUT', $URL, $body, ['Content-Type' => 'text/plain', 'Content-MD5' => $digest($body)])[0] === 403;
         yield assert(
            assertion: $refused === true && $Fake->fetch('media/inbox/bound.txt') === null,
            description: 'another type, size, body or metadata — or a bound header left out — is refused by the store'
         );

         [$status] = fake_send('PUT', $URL, $body, $sent);
         $stored = $Fake->describe('media/inbox/bound.txt');
         yield assert(
            assertion: $status === 200
               && $Fake->fetch('media/inbox/bound.txt') === $body
               && ($stored['type'] ?? '') === 'text/plain'
               && ($stored['meta'] ?? []) === ['owner' => 'seven'],
            description: 'the upload that carries every bound header with its value is stored, with the type and metadata the server chose'
         );

         // # Only the size
         $URL = (string) $S3->presign('PUT', 'inbox/sized.bin', 60, [], ['content-length' => '5']);
         yield assert(
            assertion: fake_send('PUT', $URL, 'abcdef', ['Content-Type' => 'image/png'])[0] === 403
               && fake_send('PUT', $URL, 'abcd')[0] === 403
               && fake_send('PUT', $URL, 'abcde', ['Content-Type' => 'image/png'])[0] === 200
               && $Fake->fetch('media/inbox/sized.bin') === 'abcde'
               && ($Fake->describe('media/inbox/sized.bin')['type'] ?? '') === 'image/png',
            description: 'a URL that binds only the size takes that many bytes, of any type'
         );

         // # A part bound to its size and its bytes
         $Uploads = $S3->Uploads;
         $id = (string) $Uploads->create('video.mp4', ['type' => 'video/mp4']);
         $URL = (string) $Uploads->sign('video.mp4', $id, 1, 60, ['Content-Length' => 8, 'Content-MD5' => $digest('part one')]);
         $parts = static fn (): array|false => $Uploads->list('video.mp4', $id);
         yield assert(
            assertion: str_contains($URL, '&X-Amz-SignedHeaders=content-length%3Bcontent-md5%3Bhost&') === true
               && fake_send('PUT', $URL, 'part one!', ['Content-MD5' => $digest('part one')])[0] === 403
               && fake_send('PUT', $URL, 'part two', ['Content-MD5' => $digest('part one')])[0] === 400
               && fake_send('PUT', $URL, 'part two', ['Content-MD5' => $digest('part two')])[0] === 403
               && fake_send('PUT', $URL, 'part one')[0] === 403
               && $parts() === []
               && fake_send('PUT', $URL, 'part one', ['Content-MD5' => $digest('part one')])[0] === 200
               && $parts() === [1 => ['etag' => '"' . md5('part one') . '"', 'size' => 8]],
            description: 'Uploads::sign() binds the size and the bytes of a part: the store holds that part or none'
         );

         // # What cannot be bound (no URL, no request)
         $before = count($Fake->log());
         $object = 'S3 presign: a PUT binds Content-Type, Content-Length, Content-MD5 and x-amz-meta-* only, each once, as a string';
         $part = 'S3 sign part: a part binds Content-Length and Content-MD5 only, each once, as a string';
         $refused = true;
         foreach ([
            // ? Headers that are another operation, or that are not the upload's
            ['x-amz-acl' => 'public-read'],
            ['x-amz-copy-source' => '/test-bucket/media/secret.txt'],
            ['x-amz-server-side-encryption' => 'AES256'],
            ['Cache-Control' => 'no-store'],
            ['Range' => 'bytes=0-1'],
            ['Host' => 'evil.example'],
            ['Authorization' => 'x'],
            ['text/plain'],
            // ? A metadata name that is not a header token
            ['x-amz-meta-' => 'x'],
            ['x-amz-meta-a b' => 'x'],
            ["x-amz-meta-a\r\nx-amz-acl" => 'public-read'],
            // ? Values that are not of the form of their header
            ['Content-Length' => '-1'],
            ['Content-Length' => '1.5'],
            ['Content-Length' => '01'],
            ['Content-Length' => '5 '],
            ['Content-Length' => true],
            ['Content-MD5' => 'not-a-digest'],
            ['Content-MD5' => md5('x')],
            ['Content-Type' => ''],
            ['Content-Type' => ' '],
            ['Content-Type' => "text/plain\r\nx-amz-acl: public-read"],
            ['x-amz-meta-a' => "x\ny"],
            ['x-amz-meta-a' => ''],
            ['x-amz-meta-a' => ['x']],
            ['x-amz-meta-a' => null],
            // ? The same header twice
            ['Content-Type' => 'text/plain', 'content-type' => 'text/html'],
         ] as $headers) {
            $refused = $refused && $S3->presign('PUT', 'inbox/a.txt', 60, [], $headers) === false && $S3->error === $object;
         }
         yield assert(
            assertion: $refused,
            description: 'presign() binds only the type, the size, the MD5 and the metadata of a PUT, each once and well-formed'
         );
         yield assert(
            assertion: $S3->presign('GET', 'inbox/bound.txt', 60, [], ['Content-Type' => 'text/plain']) === false && $S3->error === $object
               && $S3->presign('HEAD', 'inbox/bound.txt', 60, [], ['x-amz-meta-owner' => 'seven']) === false && $S3->error === $object
               && $S3->presign('GET', 'inbox/bound.txt', 60, [], []) !== false && $S3->error === '',
            description: 'a GET or a HEAD binds no header'
         );
         yield assert(
            assertion: $Uploads->sign('video.mp4', $id, 2, 60, ['Content-Type' => 'video/mp4']) === false && $S3->error === $part
               && $Uploads->sign('video.mp4', $id, 2, 60, ['x-amz-meta-owner' => 'seven']) === false && $S3->error === $part
               && $Uploads->sign('video.mp4', $id, 2, 60, ['Content-Length' => 'eight']) === false && $S3->error === $part
               && $Uploads->sign('video.mp4', $id, 2, 60, ['Content-Length' => 8, 'CONTENT-LENGTH' => 9]) === false && $S3->error === $part
               && $Uploads->sign('video.mp4', $id, 2, 60, []) !== false && $S3->error === ''
               && count($Fake->log()) === $before,
            description: 'a part binds its size and its MD5 only — the type and the metadata belong to the upload — and none of the refusals sends a request'
         );

         // # The fake's own rule: a credential scoped to another day than the request. Each
         //   request is signed correctly for its scope, so only that rule can refuse it
         $host = "127.0.0.1:{$Fake->port}";
         $path = '/test-bucket/media/inbox/bound.txt';
         $date = gmdate('Ymd\THis\Z');
         $derive = static function (string $day, string $request) use ($date): string {
            $derived = hash_hmac('sha256', $day, 'AWS4' . Fake::SECRET, true);
            foreach ([Fake::REGION, 's3', 'aws4_request'] as $scope) {
               $derived = hash_hmac('sha256', $scope, $derived, true);
            }

            return hash_hmac(
               'sha256',
               "AWS4-HMAC-SHA256\n{$date}\n{$day}/" . Fake::REGION . "/s3/aws4_request\n" . hash('sha256', $request),
               $derived
            );
         };
         $query = static function (string $day) use ($host, $path, $date, $derive): array {
            $canonical = 'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=' . Fake::KEY . "%2F{$day}%2F" . Fake::REGION
               . "%2Fs3%2Faws4_request&X-Amz-Date={$date}&X-Amz-Expires=60&X-Amz-SignedHeaders=host";
            $signature = $derive($day, "GET\n{$path}\n{$canonical}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD");

            return fake_send('GET', "http://{$host}{$path}?{$canonical}&X-Amz-Signature={$signature}");
         };
         $header = static function (string $day) use ($host, $path, $date, $derive): array {
            $signature = $derive($day, "GET\n{$path}\n\nhost:{$host}\nx-amz-content-sha256:UNSIGNED-PAYLOAD\nx-amz-date:{$date}\n\n"
               . "host;x-amz-content-sha256;x-amz-date\nUNSIGNED-PAYLOAD");

            return fake_send('GET', "http://{$host}{$path}", '', [
               'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . Fake::KEY . "/{$day}/" . Fake::REGION
                  . "/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$signature}",
               'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
               'x-amz-date' => $date,
            ]);
         };
         $today = substr($date, 0, 8);
         yield assert(
            assertion: $query($today)[0] === 200
               && $query('20200101')[0] === 403
               && str_contains($query('20200101')[2], '<Code>AuthorizationQueryParametersError</Code>') === true
               && $header($today)[0] === 200
               && $header('20200101')[0] === 400
               && str_contains($header('20200101')[2], '<Code>AuthorizationHeaderMalformed</Code>') === true,
            description: 'the fake refuses a request signed for a scope whose day is not the day of the request, in both auth forms'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);

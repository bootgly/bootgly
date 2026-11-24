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


use function array_column;
use function array_count_values;
use function array_filter;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function fake_skip;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function source;
use function str_contains;
use function str_repeat;
use function strlen;
use function var_export;
use function wipe;
use InvalidArgumentException;
use LogicException;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;


require_once __DIR__ . '/s3.php';


/**
 * The S3 response resource against the local fake (an independent SigV4 verifier): every
 * operation round-trips, the multipart steps work, and every failure is the driver's — the same
 * status, code, kind and words on `$Failure` / `$error`.
 */
return new Test(
   description: 'Resources: the S3 resource round-trips every operation through the worker reactor and fails like the S3 driver',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('wpi-s3-resource');
      $Fake = Fake::start($base);
      $Rig = mount();
      $MiB = 1024 * 1024;

      try {
         $Writer = new Writer('', $Fake->configure());
         $S3 = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 5, connectTimeout: 2);
         $S3->schedule($Rig->pump);

         // # Outside a deferred context the resource refuses before sending anything
         $refusal = '';
         try {
            $S3->write('a.txt', 'alpha');
         }
         catch (LogicException $Exception) {
            $refusal = $Exception->getMessage();
         }
         yield assert(
            assertion: $refusal === 'S3 response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().'
               && $Fake->log() === [],
            description: 'Outside a deferred context every call refuses and nothing reaches the store'
         );

         // # Objects: write, read (whole and a window), check, delete
         $result = run(static function () use ($S3): array {
            return [
               'write' => $S3->write('docs/a b+c.txt', 'alpha', ['type' => 'text/plain', 'meta' => ['owner' => '7']]),
               'read' => $S3->read('docs/a b+c.txt'),
               'window' => $S3->read('docs/a b+c.txt', 1, 2),
               'check' => $S3->check('docs/a b+c.txt'),
               'missing' => $S3->check('docs/missing.txt'),
               'failure' => $S3->Failure,
               'delete' => $S3->delete('docs/a b+c.txt'),
               'gone' => $S3->check('docs/a b+c.txt'),
               'again' => $S3->delete('docs/a b+c.txt'),
            ];
         });
         yield assert(
            assertion: $result === [
               'write' => true,
               'read' => 'alpha',
               'window' => 'lp',
               'check' => true,
               'missing' => false,
               'failure' => null,
               'delete' => true,
               'gone' => false,
               'again' => true,
            ],
            description: 'A key with reserved characters is written, read whole and by window, checked and deleted, found: ' . json_encode($result)
         );
         $Write = array_values(array_filter($Fake->log(), static fn (array $request): bool => $request['operation'] === 'PutObject'))[0] ?? [];
         yield assert(
            assertion: ($Write['headers']['content-type'] ?? '') === 'text/plain'
               && ($Write['headers']['x-amz-meta-owner'] ?? '') === '7'
               && in_array(403, array_column($Fake->log(), 'status'), true) === false,
            description: 'The type and metadata travel signed, and the verifier accepted every request'
         );

         // # A write larger than one part: a Multipart Upload, from a string and from a stream
         $bytes = str_repeat('0123456789abcdef', (int) (11 * $MiB / 16)) . 'tail';
         $before = count($Fake->log());
         $result = run(static fn (): array => [
            $S3->write('big/string.bin', $bytes),
            $S3->write('big/stream.bin', source($bytes)),
            $S3->write('big/exact.bin', source(str_repeat('e', 5 * $MiB))),
         ]);
         $Operations = array_count_values(array_column(array_slice($Fake->log(), $before), 'operation'));
         yield assert(
            assertion: $result === [true, true, true]
               && $Fake->fetch('big/string.bin') === $bytes
               && $Fake->fetch('big/stream.bin') === $bytes
               && strlen((string) $Fake->fetch('big/exact.bin')) === 5 * $MiB
               && ($Operations['CreateMultipartUpload'] ?? 0) === 2
               && ($Operations['UploadPart'] ?? 0) === 6
               && ($Operations['CompleteMultipartUpload'] ?? 0) === 2
               && ($Operations['PutObject'] ?? 0) === 1
               && $Fake->list() === []
               && measure() === 0,
            description: 'Three parts each for 11 MiB from a string or a stream; a stream of exactly one part is one PUT; the budget is back to 0, found: ' . json_encode($Operations)
         );

         // # The `$Uploads` handle: create, sign, put, list, complete, abort
         $part = str_repeat('p', 5 * $MiB);
         $result = run(static function () use ($S3, $part): array {
            $id = (string) $S3->Uploads->create('up/handle.bin', ['type' => 'video/mp4']);
            $first = $S3->Uploads->put('up/handle.bin', $id, 1, $part);
            $second = $S3->Uploads->put('up/handle.bin', $id, 2, 'end');
            $listed = $S3->Uploads->list('up/handle.bin', $id);
            $URL = $S3->Uploads->sign('up/handle.bin', $id, 3, 60);
            $complete = is_array($listed) && $S3->Uploads->complete('up/handle.bin', $id, $listed);

            $other = (string) $S3->Uploads->create('up/aborted.bin');
            $aborted = $S3->Uploads->abort('up/aborted.bin', $other);

            return [
               'id' => $id !== '',
               'etags' => is_string($first) && is_string($second),
               'listed' => is_array($listed) ? count($listed) : -1,
               'signed' => is_string($URL) && str_contains($URL, 'X-Amz-Signature='),
               'complete' => $complete,
               'aborted' => $aborted,
               'refused' => $S3->Uploads->put('up/handle.bin', '', 1, 'x') === false && $S3->Failure?->kind === Kinds::Refusal,
               'unsigned' => $S3->Uploads->sign('up/handle.bin', $id, 0, 60) === false && $S3->Failure?->kind === Kinds::Refusal,
            ];
         });
         yield assert(
            assertion: $result === [
               'id' => true,
               'etags' => true,
               'listed' => 2,
               'signed' => true,
               'complete' => true,
               'aborted' => true,
               'refused' => true,
               'unsigned' => true,
            ]
               && $Fake->fetch('up/handle.bin') === "{$part}end"
               && $Fake->list() === []
               && measure() === 0,
            description: 'The multipart steps work through the handle and refuse what the driver refuses, found: ' . json_encode($result)
         );

         // # Failures: the driver's status, code, kind and words
         // ! A connection dropped before any answer byte on a REUSED keep-alive connection is
         //   re-sent once by the client: the drop is scripted for both sends
         $result = run(static function () use ($S3, $Fake): array {
            $missing = [$S3->read('missing.bin'), $S3->error, $S3->Failure?->status, $S3->Failure?->code, $S3->Failure?->kind];
            $Fake->inject('GetObject', 503, 'SlowDown');
            $slow = [$S3->read('big/string.bin'), $S3->Failure?->status, $S3->Failure?->code];
            $range = [$S3->read('big/exact.bin', 6 * 1024 * 1024), $S3->error, $S3->Failure?->code];
            $Fake->inject('PutObject', 0, times: 2);
            $dropped = [$S3->write('c.txt', 'gamma'), $S3->Failure?->status, $S3->Failure?->kind];
            $refused = [$S3->write('c.txt', 'gamma', ['type' => "text/plain\r\nX-Evil: 1"]), $S3->Failure?->kind];
            $cleared = [$S3->write('c.txt', 'gamma'), $S3->Failure, $S3->error];

            return [$missing, $slow, $range, $dropped, $refused, $cleared];
         });
         yield assert(
            assertion: $result === [
               [false, 'S3 GET /test-bucket/missing.bin: HTTP 404', 404, 'NoSuchKey', Kinds::Refusal],
               [false, 503, 'SlowDown'],
               [false, 'S3 GET /test-bucket/big/exact.bin: range not satisfiable (HTTP 416)', 'InvalidRange'],
               [false, 0, Kinds::Transport],
               [false, Kinds::Refusal],
               [true, null, ''],
            ],
            description: 'A missing key, a store error, a window past the end, a dropped connection and a refused input fail as in the driver; a later success clears them, found: ' . var_export($result, true)
         );

         // # A large write: the part that failed is named, even when its abort fails too
         $Fake->inject('UploadPart', 500, 'InternalError');
         $Fake->inject('AbortMultipartUpload', 503, 'SlowDown');
         $result = run(static fn (): array => [$S3->write('big/failed.bin', $bytes), $S3->error, $S3->Failure?->status, $S3->Failure?->code]);
         $Operations = array_column($Fake->log(), 'operation');
         $abort = $Operations[count($Operations) - 1] ?? '';
         yield assert(
            assertion: $result[0] === false
               && preg_match('/^S3 upload part [12]: HTTP 500$/', (string) $result[1]) === 1
               && $result[2] === 500
               && $result[3] === 'InternalError'
               && $abort === 'AbortMultipartUpload'
               && measure() === 0,
            description: 'A failed part fails the write by name, then the upload is aborted, found: ' . json_encode($result)
         );
         // ! The abort that failed above left its upload open in the store: abort it for the next cases
         run(static function () use ($S3, $Fake): void {
            foreach ($Fake->list() as $Upload) {
               $S3->Uploads->abort($Upload['key'], $Upload['id']);
            }
         });

         // # A completion refused inside a 200: the store's code, then the upload aborted
         $Fake->inject('CompleteMultipartUpload', 200, 'InternalError');
         $result = run(static fn (): array => [$S3->write('big/complete.bin', $bytes), $S3->Failure?->status, $S3->Failure?->code, $S3->Failure?->kind]);
         yield assert(
            assertion: $result === [false, 200, 'InternalError', Kinds::Refusal]
               && $Fake->list() === []
               && $Fake->fetch('big/complete.bin') === null,
            description: 'A completion failed inside a 200 fails the write and the upload is aborted, found: ' . json_encode($result)
         );

         // # A dropped part: Transport, and the abort after it
         $Fake->inject('UploadPart', 0, times: 4);
         $result = run(static fn (): array => [$S3->write('big/dropped.bin', $bytes), $S3->Failure?->status, $S3->Failure?->kind]);
         yield assert(
            assertion: $result === [false, 0, Kinds::Transport] && $Fake->list() === [],
            description: 'A part whose connection dropped fails the write as Transport and aborts the upload, found: ' . json_encode($result)
         );

         // # Disks that cannot answer or be signed; a read past its limit
         $Closed = new S3(new Writer('', $Fake->configure(['endpoint' => 'http://127.0.0.1:1'])), part: 5 * $MiB, budget: 10 * $MiB, timeout: 2, connectTimeout: 1);
         $Closed->schedule($Rig->pump);
         $Unsignable = new S3(new Writer('', $Fake->configure(['bucket' => 'my bucket'])), part: 5 * $MiB, budget: 10 * $MiB);
         $Unsignable->schedule($Rig->pump);
         $Small = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, maxResponseBytes: 1024);
         $Small->schedule($Rig->pump);
         $result = run(static fn (): array => [
            [$Closed->check('a.txt'), $Closed->Failure?->status, $Closed->Failure?->kind],
            [$Unsignable->check('a.txt'), $Unsignable->Failure?->kind, str_contains((string) $Unsignable->Failure?->message, 'the request cannot be signed')],
            [$Small->read('big/string.bin'), $Small->Failure?->kind, $Small->error],
         ]);
         yield assert(
            assertion: $result === [
               [false, 0, Kinds::Transport],
               [false, Kinds::Refusal, true],
               [false, Kinds::Protocol, 'S3 GET /test-bucket/big/string.bin: the answer is larger than the read limit (1024 bytes)'],
            ],
            description: 'An endpoint that does not answer is Transport, a bucket that cannot be signed a Refusal, a read past its limit a Protocol failure, found: ' . json_encode($result)
         );

         // # Bounds refused at construction and at registration
         $bounds = [];
         foreach ([
            ['part' => 4 * $MiB],
            ['part' => 6 * 1024 * $MiB],
            ['part' => 8 * $MiB, 'budget' => 7 * $MiB],
            ['concurrency' => 0],
            ['timeout' => 0],
            ['connectTimeout' => -1],
            ['maxResponseBytes' => 0],
         ] as $knobs) {
            try {
               new S3($Writer, ...$knobs);
               $bounds[] = 'built';
            }
            catch (InvalidArgumentException) {
               $bounds[] = 'refused';
            }
         }
         $Storage = new Storage([
            'default' => 'files',
            'disks' => [
               'files' => ['driver' => 's3'] + $Fake->configure(),
               'local' => ['driver' => 'local', 'root' => $base],
            ],
         ]);
         $registered = [];
         foreach (['', 'files', 'local', 'nothing'] as $disk) {
            try {
               $Factory = S3::provide($Storage, $disk, part: 5 * $MiB, budget: 10 * $MiB);
               $registered[] = 'provided';
            }
            catch (InvalidArgumentException $Exception) {
               $registered[] = $Exception->getMessage();
            }
         }
         yield assert(
            assertion: $bounds === ['refused', 'refused', 'refused', 'refused', 'refused', 'refused', 'refused']
               && $registered === ['provided', 'provided', 'Storage disk is not an S3 disk: local.', 'Storage disk is not configured: nothing.'],
            description: 'Bounds out of range are refused; provide() takes the default or a named S3 disk and refuses any other, found: ' . json_encode([$bounds, $registered])
         );
      }
      finally {
         ($Rig->restore)();
         $Fake->stop();
         wipe($base);
      }
   }
);

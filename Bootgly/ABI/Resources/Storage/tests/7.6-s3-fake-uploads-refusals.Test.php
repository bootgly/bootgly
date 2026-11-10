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


use function array_filter;
use function array_values;
use function assert;
use function count;
use function fake_send;
use function fake_skip;
use function md5;
use function mkdir;
use function preg_replace;
use function str_contains;
use function str_repeat;
use function strlen;
use function wipe;
use Error;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * What the Multipart Upload handle refuses, and what it does with a store that answers
 * badly. The upload id and the part list come back from a client, so they are checked
 * before they reach a URL or an XML body; a listing that is malformed, forged or that does
 * not advance fails whole — a partial list would complete an object with a hole in it.
 */
return new Test(
   description: 'Storage(S3) fake: Uploads refuses bad ids, parts and paths, and hostile store answers',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-uploads-refusals');
      $Fake = Fake::start($base);
      // ! The Cloudflare R2 rule: every part but the last has the same size
      mkdir("{$base}/equal", 0700);
      $Equal = Fake::start("{$base}/equal", equal: true);

      // ! A verbatim 200 with an XML body
      $answer = static fn (string $body): string => "HTTP/1.1 200 OK\r\nContent-Type: application/xml\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
      $listing = static fn (string $parts, string $tail = '<IsTruncated>false</IsTruncated>'): string => $answer(
         "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ListPartsResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">{$parts}{$tail}</ListPartsResult>"
      );

      // ! The same document in UTF-16LE with a BOM: `<!DOCTYPE` is no longer a byte sequence
      $wide = static fn (string $document): string => "\xFF\xFE" . (string) preg_replace('/(.)/s', "$1\x00", $document);
      $forged = '<!DOCTYPE r [<!ENTITY e "forged">]>';

      try {
         $S3 = new S3('media', $Fake->configure());
         $Uploads = $S3->Uploads;
         $key = '/test-bucket/media/a.bin';
         $id = (string) $Uploads->create('a.bin');
         fake_send('PUT', (string) $Uploads->sign('a.bin', $id, 1, 60), 'part one');
         $ETag = '"' . md5('part one') . '"';
         $before = count($Fake->log());

         // # The handle cannot be replaced from outside
         $sealed = false;
         try {
            // @phpstan-ignore-next-line
            $S3->Uploads = new Uploads(static fn () => '', static fn () => '', static fn () => [], static fn () => true, static fn () => true);
         }
         catch (Error) {
            $sealed = true;
         }
         yield assert(
            assertion: $sealed === true && $S3->Uploads === $Uploads,
            description: '`$S3->Uploads` is read-only'
         );

         // # The upload id is an opaque printable token
         $refused = true;
         foreach (['', 'a b', "id\n", "id\r\nX-Injected: 1", "id\x00", 'idé', str_repeat('a', 1025)] as $bad) {
            $refused = $refused
               && $Uploads->sign('a.bin', $bad, 1, 60) === false && $S3->error === 'S3 sign part: invalid upload id'
               && $Uploads->list('a.bin', $bad) === false && $S3->error === 'S3 list parts: invalid upload id'
               && $Uploads->complete('a.bin', $bad, [1 => ['etag' => $ETag]]) === false && $S3->error === 'S3 complete multipart: invalid upload id'
               && $Uploads->abort('a.bin', $bad) === false && $S3->error === 'S3 abort multipart: invalid upload id';
         }
         yield assert(
            assertion: $refused && $Uploads->sign('a.bin', str_repeat('a', 1024), 1, 60) !== false,
            description: 'an upload id that is empty, over 1024 bytes or holds a space, a control or a non-ASCII byte is refused by every step'
         );

         // # The part number and the lifetime of a part URL
         yield assert(
            assertion: $Uploads->sign('a.bin', $id, 0, 60) === false && $S3->error === 'S3 sign part: the part number is 1..10000'
               && $Uploads->sign('a.bin', $id, -1, 60) === false && $S3->error === 'S3 sign part: the part number is 1..10000'
               && $Uploads->sign('a.bin', $id, 10001, 60) === false && $S3->error === 'S3 sign part: the part number is 1..10000'
               && $Uploads->sign('a.bin', $id, 10000, 60) !== false && $S3->error === '',
            description: 'sign() refuses a part number outside 1..10000'
         );
         yield assert(
            assertion: $Uploads->sign('a.bin', $id, 1, 0) === false && $S3->error === 'S3 presign: the lifetime is 1..604800 seconds'
               && $Uploads->sign('a.bin', $id, 1, 604801) === false && $S3->error === 'S3 presign: the lifetime is 1..604800 seconds'
               && $Uploads->sign('a.bin', $id, 1, 604800) !== false,
            description: 'sign() bounds the lifetime of a part URL to 1..604800 seconds'
         );

         // # The disk root is not an object
         yield assert(
            assertion: $Uploads->create('') === false && $S3->error === 'S3 create multipart: an object path is required'
               && $Uploads->create('..') === false && $S3->error === 'S3 create multipart: an object path is required'
               && $Uploads->sign('/', $id, 1, 60) === false && $S3->error === 'S3 presign: an object path is required'
               && $Uploads->list('a/..', $id) === false && $S3->error === 'S3 list parts: an object path is required'
               && $Uploads->complete('', $id, [1 => ['etag' => $ETag]]) === false && $S3->error === 'S3 complete multipart: an object path is required'
               && $Uploads->abort('../..', $id) === false && $S3->error === 'S3 abort multipart: an object path is required',
            description: 'no step runs on the disk root'
         );

         // # create(): the options
         yield assert(
            assertion: $Uploads->create('a.bin', ['type' => "video/mp4\r\nx-amz-acl: public-read"]) === false
               && $S3->error === "S3 create multipart {$key}: illegal newline in the options"
               && $Uploads->create('a.bin', ['meta' => ['owner' => "7\nx"]]) === false
               && $S3->error === "S3 create multipart {$key}: illegal newline in the options"
               && $Uploads->create('a.bin', ['meta' => ['bad name' => 'v']]) === false
               && $S3->error === "S3 create multipart {$key}: illegal character in the options"
               && $Uploads->create('a.bin', ['type' => "video/mp4\x0b"]) === false
               && $S3->error === "S3 create multipart {$key}: illegal character in the options",
            description: 'create() refuses a line break in the type or the metadata, a metadata name that is not a header token and a control character — with that reason'
         );

         // # complete(): the part list
         $required = 'S3 complete multipart: 1..10000 parts are required';
         $shape = 'S3 complete multipart: each part is [number => [\'etag\' => ...]], numbered 1..10000';
         $refused = $Uploads->complete('a.bin', $id, []) === false && $S3->error === $required;
         foreach ([
            [0 => ['etag' => $ETag]],
            [-1 => ['etag' => $ETag]],
            [10001 => ['etag' => $ETag]],
            ['one' => ['etag' => $ETag]],
            [1 => $ETag],
            [1 => ['size' => 8]],
            [1 => ['etag' => '']],
            [1 => ['etag' => '""']],
            [1 => ['etag' => 5]],
            [1 => ['etag' => "{$ETag}\n"]],
            [1 => ['etag' => "a\r\nb"]],
            [1 => ['etag' => str_repeat('a', 1025)]],
            [1 => ['etag' => $ETag], 2 => ['etag' => null]],
         ] as $Parts) {
            // @phpstan-ignore-next-line
            $refused = $refused && $Uploads->complete('a.bin', $id, $Parts) === false && $S3->error === $shape;
         }
         yield assert(
            assertion: $refused,
            description: 'complete() refuses an empty list, a part number outside 1..10000 and an ETag that is missing, empty, not a string or not one line'
         );

         // # Nothing above reached the store
         yield assert(
            assertion: count($Fake->log()) === $before && count($Fake->list()) === 1,
            description: 'every refusal happens before any request, and the upload is untouched'
         );

         // # An ETag is text in the completion, never markup
         $Uploads->complete('a.bin', $id, [1 => ['etag' => '"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>&x;']]);
         $Log = $Fake->log();
         yield assert(
            assertion: $Log[count($Log) - 1]['body'] === '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber>'
               . '<ETag>"&lt;/ETag&gt;&lt;/Part&gt;&lt;Part&gt;&lt;PartNumber&gt;2&lt;/PartNumber&gt;&lt;ETag&gt;&amp;x;</ETag>'
               . '</Part></CompleteMultipartUpload>'
               && $S3->error === "S3 complete multipart {$key}: HTTP 400"
               && count($Fake->list()) === 1,
            description: 'markup in an ETag is escaped: it cannot add a part to the completion'
         );

         // # complete(): the store's answer
         $Fake->inject('CompleteMultipartUpload', 200, 'InternalError');
         yield assert(
            assertion: $Uploads->complete('a.bin', $id, [1 => ['etag' => $ETag]]) === false
               && $S3->error === "S3 complete multipart {$key}: HTTP 200"
               && count($Fake->list()) === 1
               && $Fake->fetch('media/a.bin') === null,
            description: 'a completion answered 200 with an Error body is a failure; the upload stays open for another try'
         );
         $Fake->inject('CompleteMultipartUpload', 500);
         yield assert(
            assertion: $Uploads->complete('a.bin', $id, [1 => ['etag' => $ETag]]) === false
               && $S3->error === "S3 complete multipart {$key}: HTTP 500",
            description: 'complete() reports the HTTP status of a failed completion'
         );

         // # create(): the store's answer
         $Fake->inject('CreateMultipartUpload', 503);
         $failed = [$Uploads->create('b.bin'), $S3->error];
         $Fake->inject('CreateMultipartUpload', 200, raw: $answer('<InitiateMultipartUploadResult><Bucket>test-bucket</Bucket><UploadId></UploadId></InitiateMultipartUploadResult>'));
         $empty = [$Uploads->create('b.bin'), $S3->error];
         $Fake->inject('CreateMultipartUpload', 200, raw: $answer(
            '<!DOCTYPE r [<!ENTITY e "forged-id">]><InitiateMultipartUploadResult><UploadId>&e;</UploadId></InitiateMultipartUploadResult>'
         ));
         $entity = [$Uploads->create('b.bin'), $S3->error];
         $Fake->inject('CreateMultipartUpload', 200, raw: $answer($wide(
            "{$forged}<InitiateMultipartUploadResult><UploadId>&e;</UploadId></InitiateMultipartUploadResult>"
         )));
         $encoded = [$Uploads->create('b.bin'), $S3->error];
         $Fake->inject('CreateMultipartUpload', 200, raw: $answer(
            '<?xml version="1.0" encoding="UTF-7"?>+ADwAIQ-DOCTYPE r +AFsAPAAh-ENTITY e +ACI-forged+ACIAPgBdAD4-'
            . '<InitiateMultipartUploadResult><UploadId>+ACY-e;</UploadId></InitiateMultipartUploadResult>'
         ));
         $declared = [$Uploads->create('b.bin'), $S3->error];
         yield assert(
            assertion: $failed === [false, 'S3 create multipart /test-bucket/media/b.bin: HTTP 503']
               && $empty === [false, 'S3 create multipart /test-bucket/media/b.bin: missing UploadId']
               && $entity === [false, 'S3 create multipart /test-bucket/media/b.bin: missing UploadId']
               && $encoded === [false, 'S3 create multipart /test-bucket/media/b.bin: missing UploadId']
               && $declared === [false, 'S3 create multipart /test-bucket/media/b.bin: missing UploadId'],
            description: 'create() reports a failed request, an answer with no UploadId, and never expands an entity into an id — not in UTF-16, not under a declared charset'
         );

         // # list(): the store's answer — all or nothing
         $Fake->inject('ListParts', 500);
         $status = [$Uploads->list('a.bin', $id), $S3->error];
         $malformed = "S3 list parts {$key}: malformed XML response";
         $refused = true;
         foreach ([
            $answer('not xml at all'),
            $answer(''),
            $answer('<ListPartsResult><Part><PartNumber>1</PartNumber>'),
            $answer('<Error><Code>InternalError</Code></Error>'),
            $answer('<!DOCTYPE r [<!ENTITY e "x">]><ListPartsResult><Part><PartNumber>1</PartNumber><ETag>&e;</ETag><Size>1</Size></Part></ListPartsResult>'),
            $answer($wide("{$forged}<ListPartsResult><Part><PartNumber>1</PartNumber><ETag>&e;</ETag><Size>1</Size></Part></ListPartsResult>")),
            $answer($wide('<ListPartsResult><Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part></ListPartsResult>')),
            $answer('<?xml version="1.0" encoding="ISO-8859-1"?><ListPartsResult><Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part></ListPartsResult>'),
            $answer("garbage<ListPartsResult></ListPartsResult>"),
            $listing('<Part><PartNumber>0</PartNumber><ETag>"a"</ETag><Size>1</Size></Part>'),
            $listing('<Part><PartNumber>10001</PartNumber><ETag>"a"</ETag><Size>1</Size></Part>'),
            $listing('<Part><PartNumber>one</PartNumber><ETag>"a"</ETag><Size>1</Size></Part>'),
            $listing('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part>'),
            $listing('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>-1</Size></Part>'),
            $listing('<Part><PartNumber>1</PartNumber><ETag></ETag><Size>1</Size></Part>'),
            $listing('<Part><PartNumber>2</PartNumber><ETag>"a"</ETag><Size>1</Size></Part><Part><PartNumber>1</PartNumber><ETag>"b"</ETag><Size>1</Size></Part>'),
            $listing('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part><Part><PartNumber>1</PartNumber><ETag>"b"</ETag><Size>1</Size></Part>'),
         ] as $raw) {
            $Fake->inject('ListParts', 200, raw: $raw);
            $refused = $refused && $Uploads->list('a.bin', $id) === false && $S3->error === $malformed;
         }
         yield assert(
            assertion: $status === [false, "S3 list parts {$key}: HTTP 500"] && $refused,
            description: 'list() is false on a failed page and on a listing that is not XML, declares a DOCTYPE, is not UTF-8, is an Error, or holds a part without a valid number, size or ETag, out of order or twice'
         );
         $stalled = "S3 list parts {$key}: the listing does not advance";
         $refused = true;
         foreach (['0', '1', '', 'x', '100000', '5', '2'] as $marker) {
            $Fake->inject('ListParts', 200, raw: $listing(
               '<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part>',
               "<NextPartNumberMarker>{$marker}</NextPartNumberMarker><IsTruncated>true</IsTruncated>"
            ));
            // ! Marker 1 is followed by a second page that does not move past it
            if ($marker === '1') {
               $Fake->inject('ListParts', 200, raw: $listing('', '<NextPartNumberMarker>1</NextPartNumberMarker><IsTruncated>true</IsTruncated>'));
            }
            $refused = $refused && $Uploads->list('a.bin', $id) === false && $S3->error === $stalled;
         }
         yield assert(
            assertion: $refused,
            description: 'list() is false — never an endless loop, never a partial list — when the marker of a truncated page is not its last part: missing, stuck, or jumping ahead over parts'
         );
         $Fake->inject('ListParts', 200, raw: $listing(
            '<Part><PartNumber>1</PartNumber><LastModified>2026-11-08T12:30:00.000Z</LastModified><ETag>&quot;a&quot;</ETag><Size>12</Size></Part>',
            '<IsTruncated>FALSE</IsTruncated>'
         ));
         yield assert(
            assertion: $Uploads->list('a.bin', $id) === [1 => ['etag' => '"a"', 'size' => 12]] && $S3->error === '',
            description: 'a well-formed listing with a namespace and escaped quotes is read'
         );

         // # An answer too large to be an S3 document is not buffered
         $page = "HTTP/1.1 200 OK\r\nContent-Type: application/xml\r\n\r\n"
            . '<ListPartsResult><Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>12</Size></Part><IsTruncated>false</IsTruncated></ListPartsResult>';
         $Fake->inject('ListParts', 200, raw: $page, pad: 4096);
         $padded = $Uploads->list('a.bin', $id);
         $Fake->inject('ListParts', 200, raw: $page, pad: 33 * 1024 * 1024);
         yield assert(
            assertion: $padded === [1 => ['etag' => '"a"', 'size' => 12]]
               && $Uploads->list('a.bin', $id) === false
               && $S3->error === "S3 list parts {$key}: HTTP 0",
            description: 'a listing followed by 4 KiB of white space is read; the same listing in a 33 MiB answer is refused, not buffered'
         );

         // # abort(): the store's answer
         $Fake->inject('AbortMultipartUpload', 500);
         yield assert(
            assertion: $Uploads->abort('a.bin', $id) === false
               && $S3->error === "S3 abort multipart {$key}: HTTP 500"
               && count($Fake->list()) === 1
               && $Uploads->abort('a.bin', $id) === true
               && $Fake->list() === [],
            description: 'abort() reports a failed abort — the upload is still there — and succeeds on the retry'
         );

         // # A store that requires equal part sizes (Cloudflare R2): list() shows the sizes first
         $R2 = new S3('', $Equal->configure());
         $uneven = (string) $R2->Uploads->create('uneven.bin');
         foreach ([1 => 'aaaaa', 2 => 'bbb', 3 => 'cc'] as $number => $bytes) {
            fake_send('PUT', (string) $R2->Uploads->sign('uneven.bin', $uneven, $number, 60), $bytes);
         }
         $Sizes = (array) $R2->Uploads->list('uneven.bin', $uneven);
         yield assert(
            assertion: [$Sizes[1]['size'] ?? 0, $Sizes[2]['size'] ?? 0, $Sizes[3]['size'] ?? 0] === [5, 3, 2]
               && $R2->Uploads->complete('uneven.bin', $uneven, $Sizes) === false
               && $R2->error === 'S3 complete multipart /test-bucket/uneven.bin: HTTP 400'
               && $Equal->fetch('uneven.bin') === null,
            description: 'parts of different sizes are listed with their sizes, and a store that requires equal parts refuses the completion'
         );
         fake_send('PUT', (string) $R2->Uploads->sign('uneven.bin', $uneven, 2, 60), 'bbbbb');
         yield assert(
            assertion: $R2->Uploads->complete('uneven.bin', $uneven, (array) $R2->Uploads->list('uneven.bin', $uneven)) === true
               && $Equal->fetch('uneven.bin') === 'aaaaabbbbbcc',
            description: 'the same upload completes once every part but the last has the same size'
         );

         // # No request the driver signed was refused by the signature check
         $Denied = array_values(array_filter(
            [...$Fake->log(), ...$Equal->log()],
            static fn (array $Request): bool => $Request['status'] === 403
         ));
         yield assert(
            assertion: $Denied === [] && str_contains($S3->error . $R2->error, Fake::SECRET) === false,
            description: 'no request was refused by the signature check, and no error carries the secret'
         );
      }
      finally {
         $Equal->stop();
         $Fake->stop();
         wipe($base);
      }
   }
);

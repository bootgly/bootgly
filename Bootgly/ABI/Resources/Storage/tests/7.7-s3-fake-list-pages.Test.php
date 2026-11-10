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
use function assert;
use function base64_encode;
use function count;
use function fake_skip;
use function preg_replace;
use function strlen;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `S3::list()` with a store that pages badly. The listing follows `NextContinuationToken`
 * for as long as a page says it is truncated, so a token that repeats — or is missing —
 * must end it with a reason instead of asking forever, and a page that is not a UTF-8
 * `ListBucketResult` without a document type is not a listing. `clear()` goes through the
 * same loop and deletes nothing when it fails.
 */
return new Test(
   description: 'Storage(S3) fake: list() ends on a token that does not advance and refuses a page that is not a listing',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-list-pages');
      // ! Two keys per ListObjectsV2 page, so the honest listing below pages
      $Fake = Fake::start($base, page: 2);

      // ! A verbatim 200 with an XML body
      $answer = static fn (string $body): string => "HTTP/1.1 200 OK\r\nContent-Type: application/xml\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
      $page = static fn (string $inner): string => $answer(
         "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ListBucketResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">{$inner}</ListBucketResult>"
      );
      $entry = static fn (string $key): string => "<Contents><Key>media/{$key}</Key><Size>1</Size></Contents>";
      // ! The same document in UTF-16LE with a BOM: `<!DOCTYPE` is no longer a byte sequence
      $wide = static fn (string $document): string => "\xFF\xFE" . (string) preg_replace('/(.)/s', "$1\x00", $document);

      try {
         $S3 = new S3('media', $Fake->configure());
         $keys = ['a.txt', 'b.txt', 'c.txt', 'd.txt', 'e.txt'];
         foreach ($keys as $key) {
            $Fake->seed("media/{$key}", $key);
         }
         $listed = static fn (): int => count(array_filter(
            $Fake->log(),
            static fn (array $Request): bool => $Request['operation'] === 'ListObjectsV2'
         ));
         $deleted = static fn (): int => count(array_filter(
            $Fake->log(),
            static fn (array $Request): bool => $Request['operation'] === 'DeleteObject'
         ));
         $stalled = 'S3 list media/: the listing does not advance';
         $malformed = 'S3 list media/: malformed XML response';

         // # Control — an honest store: three pages, every key once
         yield assert(
            assertion: $S3->list('', true) === $keys && $S3->error === '' && $listed() === 3,
            description: 'a listing that advances is followed to its last page'
         );

         // # The same token on every page
         $before = $listed();
         $Fake->inject('ListObjectsV2', 200, times: 40, raw: $page(
            "{$entry('a.txt')}<IsTruncated>true</IsTruncated><NextContinuationToken>again</NextContinuationToken>"
         ));
         $partial = $S3->list('', true);
         yield assert(
            assertion: $S3->error === $stalled && $partial === ['a.txt', 'a.txt'] && $listed() - $before === 2,
            description: 'a page that hands back the token it was asked with ends the listing, with the reason on $error'
         );
         // ! Drain what the refused listing left scripted
         for ($round = 0; $round < 40 && $S3->list('', true) !== $keys; $round++) {
            continue;
         }

         // # A cycle of tokens
         $before = $listed();
         foreach (['one', 'two', 'one'] as $token) {
            $Fake->inject('ListObjectsV2', 200, raw: $page(
               "{$entry('a.txt')}<IsTruncated>true</IsTruncated><NextContinuationToken>{$token}</NextContinuationToken>"
            ));
         }
         $S3->list('', true);
         yield assert(
            assertion: $S3->error === $stalled && $listed() - $before === 3,
            description: 'a token already followed ends the listing too — a cycle is not an advance'
         );

         // # A truncated page with no token
         $Fake->inject('ListObjectsV2', 200, raw: $page("{$entry('a.txt')}<IsTruncated>true</IsTruncated>"));
         $partial = $S3->list('', true);
         yield assert(
            assertion: $S3->error === $stalled && $partial === ['a.txt'],
            description: 'a truncated page that names no next token is a failed listing, never a complete one'
         );

         // # `IsTruncated` in another letter case still pages
         $Fake->inject('ListObjectsV2', 200, raw: $page(
            "{$entry('a.txt')}{$entry('b.txt')}<IsTruncated> True </IsTruncated><NextContinuationToken>"
               . base64_encode('media/b.txt') . '</NextContinuationToken>'
         ));
         yield assert(
            assertion: $S3->list('', true) === $keys && $S3->error === '',
            description: 'a page truncated as "True" is followed, not taken for the last one'
         );

         // # A page that is not a listing
         $refused = true;
         foreach ([
            // ? A document type — entity expansion
            $answer('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e "media/forged.txt">]><ListBucketResult><Contents><Key>&e;</Key></Contents><IsTruncated>false</IsTruncated></ListBucketResult>'),
            // ? The same, hidden in UTF-16
            $answer($wide('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY e "media/forged.txt">]><ListBucketResult><Contents><Key>&e;</Key></Contents><IsTruncated>false</IsTruncated></ListBucketResult>')),
            // ? The keyword — or the declared encoding — joined by the removal of an `xmlns`
            $answer('<?xml version="1.0"?><!DOC xmlns=""TYPE r [<!ENT xmlns=""ITY e "media/forged.txt">]><ListBucketResult><Contents><Key>&e;</Key></Contents><IsTruncated>false</IsTruncated></ListBucketResult>'),
            $answer('<?xml version="1.0" enco xmlns=""ding="ISO-8859-1"?><ListBucketResult><Contents><Key>media/' . "\xE9" . '.txt</Key></Contents><IsTruncated>false</IsTruncated></ListBucketResult>'),
            // ? Another document under a 200
            $answer('<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>'),
            // ? Not XML
            $answer('ok'),
         ] as $raw) {
            $Fake->inject('ListObjectsV2', 200, raw: $raw);
            $refused = $refused && $S3->list('', true) === [] && $S3->error === $malformed;
         }
         yield assert(
            assertion: $refused,
            description: 'a page that declares a document type, is not UTF-8 or is not a ListBucketResult is refused as malformed'
         );

         // # clear() over a listing that fails deletes nothing
         $Fake->inject('ListObjectsV2', 200, times: 40, raw: $page(
            "{$entry('a.txt')}<IsTruncated>true</IsTruncated><NextContinuationToken>again</NextContinuationToken>"
         ));
         $cleared = $S3->clear();
         yield assert(
            assertion: $cleared === false && $S3->error === $stalled && $deleted() === 0
               && $Fake->fetch('media/a.txt') === 'a.txt',
            description: 'clear() over a listing that does not advance fails and deletes no key'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);

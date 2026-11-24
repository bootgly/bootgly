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
use function is_array;
use function str_repeat;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Operations;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The answer reader of the S3 driver, offline: which answer is the success of which operation,
 * the failure (status, code, kind) of every other one, and the payloads it takes apart — whatever
 * transport received them.
 */
return new Test(
   description: 'Storage(S3) offline: Reader judges each answer by its operation and reads its payload, never throwing',
   test: function () {
      $error = static fn (string $code): string => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>{$code}</Code><Message>m</Message></Error>";

      // # The success set of every operation: [operation, status, body, success?]
      $table = [
         [Operations::PutObject, 200, '', true],
         [Operations::PutObject, 204, '', true],
         [Operations::PutObject, 404, '', false],
         [Operations::CreateMultipartUpload, 200, '', true],
         [Operations::UploadPart, 200, '', true],
         [Operations::UploadPart, 302, '', false],
         [Operations::CopyObject, 200, '<CopyObjectResult/>', true],
         [Operations::CopyObject, 200, $error('InternalError'), false],
         [Operations::CompleteMultipartUpload, 200, '<CompleteMultipartUploadResult/>', true],
         [Operations::CompleteMultipartUpload, 200, $error('InternalError'), false],
         [Operations::GetObject, 200, '', true],
         [Operations::GetObject, 206, '', false],
         [Operations::HeadObject, 200, '', true],
         [Operations::HeadObject, 204, '', false],
         [Operations::ListObjectsV2, 200, '', true],
         [Operations::ListParts, 200, '', true],
         [Operations::ListParts, 201, '', false],
         [Operations::DeleteObject, 204, '', true],
         [Operations::DeleteObject, 200, '', true],
         [Operations::DeleteObject, 404, $error('NoSuchKey'), true],
         [Operations::DeleteObject, 404, '', true],
         [Operations::DeleteObject, 404, $error('NoSuchBucket'), false],
         [Operations::AbortMultipartUpload, 404, $error('NoSuchBucket'), false],
         [Operations::DeleteObject, 404, '<html><body>Not Found</body></html>', false],
         [Operations::AbortMultipartUpload, 404, '', true],
         [Operations::DeleteObject, 404, "\r\n", true],
         [Operations::DeleteObject, 403, $error('AccessDenied'), false],
         [Operations::AbortMultipartUpload, 404, $error('NoSuchUpload'), true],
         [Operations::AbortMultipartUpload, 500, '', false],
      ];
      $judged = true;
      foreach ($table as [$Operation, $status, $body, $success]) {
         $Failure = Reader::judge($Operation, $status, [], $body, 'S3 X');
         $judged = $judged && ($Failure === null) === $success;
      }
      yield assert(
         assertion: $judged === true,
         description: 'judge() accepts the success set of each operation, and only it'
      );

      // # The failure of what is not a success
      $Refused = Reader::judge(Operations::GetObject, 404, [], $error('NoSuchKey'), 'S3 GET /b/k');
      $Dropped = Reader::judge(Operations::PutObject, 0, [], '', 'S3 PUT /b/k');
      $Odd = Reader::judge(Operations::HeadObject, 204, [], '', 'S3 HEAD /b/k');
      $Inside = Reader::judge(Operations::CopyObject, 200, [], $error('SlowDown'), 'S3 COPY /b/k');
      yield assert(
         assertion: $Refused?->status === 404
            && $Refused->code === 'NoSuchKey'
            && $Refused->kind === Kinds::Refusal
            && $Refused->message === 'S3 GET /b/k: HTTP 404'
            && $Dropped?->status === 0
            && $Dropped->code === ''
            && $Dropped->kind === Kinds::Transport
            && $Dropped->message === 'S3 PUT /b/k: HTTP 0'
            && $Odd?->kind === Kinds::Protocol
            && $Odd->status === 204
            && $Inside?->kind === Kinds::Refusal
            && $Inside->status === 200
            && $Inside->code === 'SlowDown',
         description: 'status 0 is Transport, a 2xx outside the set Protocol, a store error (even inside a 200) a Refusal with its code'
      );
      $codes = [];
      foreach (["No\nKey", 'A B', str_repeat('A', 65), '1Code', 'Slow-Down'] as $code) {
         $codes[] = Reader::judge(Operations::PutObject, 500, [], $error($code), 'S3 X')?->code;
      }
      yield assert(
         assertion: $codes === ['', '', '', '', '']
            && Reader::judge(Operations::PutObject, 500, [], $error(str_repeat('A', 64)), 'S3 X')?->code === str_repeat('A', 64)
            && Reader::judge(Operations::PutObject, 500, [], '<html>busy</html>', 'S3 X')?->code === ''
            && Reader::judge(Operations::PutObject, 500, [], '<!DOCTYPE x><Error><Code>X</Code></Error>', 'S3 X')?->code === '',
         description: 'only a code of the S3 form is kept: letters, digits and dots, at most 64, from an Error document'
      );

      // # A ranged read
      $window = [10, 5];
      yield assert(
         assertion: Reader::judge(Operations::GetObject, 206, ['content-range' => 'bytes 10-14/100'], '', 'S3 GET /b/k', $window) === null
            && Reader::judge(Operations::GetObject, 206, ['content-range' => 'bytes 0-14/100'], '', 'S3 GET /b/k', $window)?->kind === Kinds::Protocol
            && Reader::judge(Operations::GetObject, 200, [], '', 'S3 GET /b/k', $window)?->message === 'S3 GET /b/k: range ignored by the endpoint (HTTP 200)'
            && Reader::judge(Operations::GetObject, 416, [], $error('InvalidRange'), 'S3 GET /b/k', $window)?->code === 'InvalidRange'
            && Reader::judge(Operations::GetObject, 503, [], $error('SlowDown'), 'S3 GET /b/k', $window)?->code === 'SlowDown',
         description: 'a ranged read succeeds only on a 206 of the window asked for'
      );
      yield assert(
         assertion: Reader::frame(['content-range' => 'bytes 10-14/100'], [10, 5]) === 5
            && Reader::frame(['content-range' => 'bytes 10-11/12'], [10, 5]) === 2
            && Reader::frame(['content-range' => 'bytes 10-99/100'], [10, null]) === 90
            && Reader::frame(['content-range' => 'bytes 10-12/*'], [10, 5]) === 3
            && Reader::frame(['content-range' => 'bytes 10-20/*'], [10, 5]) === 0
            && Reader::frame(['content-range' => 'bytes 10-13/100'], [10, 5]) === 0
            && Reader::frame(['content-range' => 'bytes 11-14/100'], [10, 5]) === 0
            && Reader::frame(['content-range' => ['bytes 10-14/100']], [10, 5]) === 5
            && Reader::frame([], [10, 5]) === 0,
         description: 'frame() is the byte count of the window asked for, 0 for any other range'
      );

      // # Payloads
      $Missing = Reader::identify('<InitiateMultipartUploadResult><Bucket>b</Bucket></InitiateMultipartUploadResult>', 'S3 create multipart /b/k');
      yield assert(
         assertion: Reader::identify('<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><UploadId>u-1</UploadId></InitiateMultipartUploadResult>', 'S3 X') === 'u-1'
            && $Missing instanceof Failure
            && $Missing->kind === Kinds::Protocol
            && $Missing->message === 'S3 create multipart /b/k: missing UploadId'
            && Reader::identify('not xml', 'S3 X') instanceof Failure,
         description: 'identify() reads the upload id, or fails as Protocol without one'
      );
      yield assert(
         assertion: Reader::tag(['etag' => '"abc"'], 'S3 X') === '"abc"'
            && Reader::tag(['etag' => ['"abc"', '"def"']], 'S3 X') === '"abc"'
            && Reader::tag([], 'S3 upload part 2')?->message === 'S3 upload part 2: missing or malformed ETag'
            && Reader::tag(['etag' => '""'], 'S3 X') instanceof Failure
            && Reader::tag(['etag' => "\"a\x01\""], 'S3 X') instanceof Failure
            && Reader::tag(['etag' => str_repeat('a', 1025)], 'S3 X') instanceof Failure,
         description: 'tag() reads the ETag as sent, or fails as Protocol for one complete() could not send back'
      );
      $page = static fn (string $inner): string => "<ListBucketResult>{$inner}</ListBucketResult>";
      $Stuck = Reader::index($page('<Contents><Key>a</Key></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>t</NextContinuationToken>'), ['t' => true], 'S3 list x/');
      yield assert(
         assertion: Reader::index($page('<Contents><Key>a</Key></Contents><Contents><Key>b</Key></Contents><IsTruncated>false</IsTruncated>'), [], 'S3 X') === [['a', 'b'], '']
            && Reader::index($page('<Contents><Key>a</Key></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>t</NextContinuationToken>'), [], 'S3 X') === [['a'], 't']
            && is_array($Stuck) === true
            && $Stuck[0] === ['a']
            && $Stuck[1] instanceof Failure
            && $Stuck[1]->message === 'S3 list x/: the listing does not advance'
            && Reader::index('<Error/>', [], 'S3 X') instanceof Failure,
         description: 'index() reads a listing page and its next token; a page that does not advance keeps its keys and fails'
      );
      $parts = static fn (string $inner): string => "<ListPartsResult>{$inner}</ListPartsResult>";
      yield assert(
         assertion: Reader::enumerate($parts('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>5</Size></Part><Part><PartNumber>3</PartNumber><ETag>"c"</ETag><Size>1</Size></Part><IsTruncated>false</IsTruncated>'), 0, 'S3 X')
               === [[1 => ['etag' => '"a"', 'size' => 5], 3 => ['etag' => '"c"', 'size' => 1]], null]
            && Reader::enumerate($parts('<Part><PartNumber>2</PartNumber><ETag>"b"</ETag><Size>5</Size></Part><IsTruncated>true</IsTruncated><NextPartNumberMarker>2</NextPartNumberMarker>'), 1, 'S3 X')
               === [[2 => ['etag' => '"b"', 'size' => 5]], 2]
            && Reader::enumerate($parts('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>5</Size></Part><IsTruncated>false</IsTruncated>'), 1, 'S3 X') instanceof Failure
            && Reader::enumerate($parts('<Part><PartNumber>' . (Uploads::PARTS + 1) . '</PartNumber><ETag>"a"</ETag><Size>5</Size></Part>'), 0, 'S3 X') instanceof Failure
            && Reader::enumerate($parts('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>5</Size></Part><IsTruncated>true</IsTruncated><NextPartNumberMarker>3</NextPartNumberMarker>'), 0, 'S3 X') instanceof Failure
            && Reader::enumerate($parts('<IsTruncated>true</IsTruncated><NextPartNumberMarker>0</NextPartNumberMarker>'), 0, 'S3 X') instanceof Failure,
         description: 'enumerate() reads the parts past the marker, and fails a page that repeats, skips or loops'
      );
      yield assert(
         assertion: Reader::decode('<a xmlns="urn:x"><b>1</b></a>')?->b->__toString() === '1'
            && Reader::decode('') === null
            && Reader::decode('text') === null
            && Reader::decode('<!DOCTYPE a [<!ENTITY e "x">]><a>&e;</a>') === null
            && Reader::decode("<\x00a/>") === null
            && Reader::decode('<?xml version="1.0" encoding="UTF-16"?><a/>') === null
            && Reader::decode('<a>') === null,
         description: 'decode() parses an S3 answer, never a document type, another encoding or broken markup'
      );
   }
);

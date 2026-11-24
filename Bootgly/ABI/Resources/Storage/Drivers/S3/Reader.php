<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers\S3;


use const LIBXML_NONET;
use function count;
use function hex2bin;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function ltrim;
use function md5;
use function preg_match;
use function preg_replace;
use function simplexml_load_string;
use function str_starts_with;
use function stripos;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use SimpleXMLElement;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;


/**
 * Reads what an S3 store answered — transport-free: it takes a status, the headers and the body,
 * however they arrived.
 *
 * `judge()` decides whether an answer is the success of its operation and, when it is not, builds
 * the `Failure` (status, the store's `<Code>`, kind). The other readers take the payload of a
 * successful answer apart: the upload id, the ETag of a part, one page of a listing, the window of
 * a ranged read. Every reader that can fail returns a `Failure` instead of its value, never throws.
 *
 * An XML answer is parsed without network access and without a document type, so no entity is
 * ever expanded. The `<Code>` is kept only when it has the form of an S3 error code; the store's
 * `<Message>` and the rest of an error body (which may echo the signed request) never leave here.
 */
final class Reader
{
   /** The highest part number of an upload (`Uploads::PARTS`, a later sibling). */
   private const int PARTS = 10000;


   /**
    * Judge one answer: null when it is the success of `$Operation`, else its `Failure`.
    *
    * The success sets: `PutObject`, `CreateMultipartUpload` and `UploadPart` any `2xx`;
    * `CopyObject` and `CompleteMultipartUpload` a `2xx` that carries no `<Error>` (S3 reports a
    * failure of either inside a `200`); `GetObject` a `200` — or, with a `$window`, a `206` whose
    * `Content-Range` is that window; `HeadObject`, `ListObjectsV2` and `ListParts` a `200`;
    * `DeleteObject` and `AbortMultipartUpload` `200`, `204` or a `404` that says what is gone is
    * gone (an empty or blank body, `NoSuchKey`, `NoSuchUpload`) — never another `404` (`NoSuchBucket`, a
    * proxy's page): a missing bucket or a wrong endpoint deleted nothing.
    *
    * Anything else: status 0 is a `Transport` failure, a `2xx` outside the set a `Protocol` one,
    * any other status a `Refusal` carrying the `<Code>` of its body. The message is `$subject`
    * followed by the status (`S3 PUT /bucket/key: HTTP 403`).
    *
    * @param array<string,string|array<int,string>> $headers Lowercase names, as an HTTP client gives them.
    * @param string $body The buffered body; an error body is enough (a streamed success has none).
    * @param string $subject What the request was, as the message starts (`S3 GET /bucket/key`).
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged `GetObject` asked for.
    */
   public static function judge (
      Operations $Operation,
      int $status,
      array $headers,
      string $body,
      string $subject,
      null|array $window = null
   ): null|Failure
   {
      // ?
      if ($status === 0) {
         return new Failure(0, '', Kinds::Transport, "{$subject}: HTTP 0");
      }

      // # A ranged read: its own answers
      if ($Operation === Operations::GetObject && $window !== null) {
         // ?:
         if ($status === 206) {
            return self::frame($headers, $window) > 0
               ? null
               : new Failure(206, '', Kinds::Protocol, 'S3 GET: the answer is not the requested range');
         }

         // :
         return match ($status) {
            416 => new Failure(416, self::code($body), Kinds::Refusal, "{$subject}: range not satisfiable (HTTP 416)"),
            200 => new Failure(200, '', Kinds::Protocol, "{$subject}: range ignored by the endpoint (HTTP 200)"),
            default => self::refuse($status, $body, $subject)
         };
      }

      $success = $status >= 200 && $status < 300;
      $accepted = match ($Operation) {
         Operations::PutObject,
         Operations::CreateMultipartUpload,
         Operations::UploadPart => $success,
         Operations::CopyObject,
         Operations::CompleteMultipartUpload => $success && strpos($body, '<Error') === false,
         Operations::GetObject,
         Operations::HeadObject,
         Operations::ListObjectsV2,
         Operations::ListParts => $status === 200,
         Operations::DeleteObject,
         Operations::AbortMultipartUpload => $status === 200 || $status === 204
            || ($status === 404 && (trim($body) === '' || in_array(self::code($body), ['NoSuchKey', 'NoSuchUpload'], true)))
      };

      // :
      return $accepted === true ? null : self::refuse($status, $body, $subject);
   }

   /**
    * The upload id of a `CreateMultipartUpload` answer, or a `Protocol` failure when it has none.
    */
   public static function identify (string $body, string $subject): string|Failure
   {
      $XML = self::decode($body);
      $id = $XML !== null ? (string) $XML->UploadId : '';

      // :
      return $id !== ''
         ? $id
         : new Failure(200, '', Kinds::Protocol, "{$subject}: missing UploadId");
   }

   /**
    * The ETag of an `UploadPart` answer, as the store sent it (quoted) — or a `Protocol` failure
    * when there is none, or one that `complete()` could not send back (empty, longer than 1024
    * bytes, or outside printable ASCII).
    *
    * @param array<string,string|array<int,string>> $headers Lowercase names.
    */
   public static function tag (array $headers, string $subject): string|Failure
   {
      $value = $headers['etag'] ?? null;
      $etag = is_array($value) === true ? ($value[0] ?? null) : $value;

      // ?
      if ($etag === null || preg_match('/^[\x20-\x7e]{1,1024}$/D', $etag) !== 1 || trim($etag, '" ') === '') {
         return new Failure(200, '', Kinds::Protocol, "{$subject}: missing or malformed ETag");
      }

      // :
      return $etag;
   }

   /**
    * One `ListObjectsV2` page: the keys it lists (as stored, prefix included) and the token of the
    * next page — `''` on the last one, or a `Protocol` failure for a truncated page whose token is
    * missing or already followed (it would ask for the same pages forever; the keys it lists are
    * still read). A page that is not a listing is a `Protocol` failure.
    *
    * @param array<string,bool> $followed The tokens already followed, as keys.
    *
    * @return array{0:array<int,string>,1:string|Failure}|Failure
    */
   public static function index (string $body, array $followed, string $subject): array|Failure
   {
      $XML = self::decode($body);
      // ?
      if ($XML === null || $XML->getName() !== 'ListBucketResult') {
         return new Failure(200, '', Kinds::Protocol, "{$subject}: malformed XML response");
      }

      $keys = [];
      foreach ($XML->Contents as $Content) {
         $keys[] = (string) $Content->Key;
      }

      // ?: The last page
      if (strtolower(trim((string) $XML->IsTruncated)) !== 'true') {
         return [$keys, ''];
      }
      $token = (string) $XML->NextContinuationToken;
      // ? A truncated page names the next one: no token, or one already followed, would ask for
      //   the same pages forever
      if ($token === '' || isset($followed[$token]) === true) {
         return [$keys, new Failure(200, '', Kinds::Protocol, "{$subject}: the listing does not advance")];
      }

      // :
      return [$keys, $token];
   }

   /**
    * One `ListParts` page read after `$marker`: the parts it lists, keyed by part number, and the
    * marker of the next page (null on the last one). Part numbers strictly ascending past the
    * marker, 1..10000, each with a size and an ETag — and a truncated page's marker equal to its
    * last part — or the page is a `Protocol` failure: a partial or repeated list would complete
    * an object with a hole in it.
    *
    * @return array{0:array<int,array{etag:string,size:int}>,1:null|int}|Failure
    */
   public static function enumerate (string $body, int $marker, string $subject): array|Failure
   {
      $XML = self::decode($body);
      // ?
      if ($XML === null || $XML->getName() !== 'ListPartsResult') {
         return new Failure(200, '', Kinds::Protocol, "{$subject}: malformed XML response");
      }

      $parts = [];
      $last = $marker;
      foreach ($XML->Part as $Part) {
         $number = (string) $Part->PartNumber;
         $size = (string) $Part->Size;
         $etag = trim((string) $Part->ETag);
         // ? Strictly ascending part numbers 1..10000, each past the marker, a size and an ETag
         if (
            preg_match('/^[1-9]\d{0,4}$/D', $number) !== 1
            || (int) $number > self::PARTS
            || (int) $number <= $last
            || preg_match('/^\d{1,18}$/D', $size) !== 1
            || $etag === ''
         ) {
            return new Failure(200, '', Kinds::Protocol, "{$subject}: malformed XML response");
         }
         $last = (int) $number;
         $parts[$last] = ['etag' => $etag, 'size' => (int) $size];
      }

      // ?: The last page
      if (strtolower(trim((string) $XML->IsTruncated)) !== 'true') {
         return [$parts, null];
      }
      $next = (string) $XML->NextPartNumberMarker;
      // ? The marker of a truncated page is its last part: a page with no part would loop
      //   forever, a marker short of the last part would list parts twice, and one past it would
      //   skip parts
      if (preg_match('/^\d{1,5}$/D', $next) !== 1 || $last <= $marker || (int) $next !== $last) {
         return new Failure(200, '', Kinds::Protocol, "{$subject}: the listing does not advance");
      }

      // :
      return [$parts, (int) $next];
   }

   /**
    * The number of bytes a `206` answer must carry for a ranged read, from its `Content-Range`;
    * 0 when that is not the window asked for — it must start at the offset and end where the
    * request ended, or at the end of the object when that comes first.
    *
    * @param array<string,string|array<int,string>> $headers Lowercase names.
    * @param array{0:int,1:null|int} $window The `[offset, length]` asked for.
    */
   public static function frame (array $headers, array $window): int
   {
      [$offset, $length] = $window;
      $value = $headers['content-range'] ?? null;
      $range = is_array($value) === true ? ($value[0] ?? null) : $value;
      // ?
      if ($range === null || preg_match('#^bytes (\d{1,18})-(\d{1,18})/(\d{1,18}|\*)$#D', trim($range), $matches) !== 1) {
         return 0;
      }
      $first = (int) $matches[1];
      $last = (int) $matches[2];
      if ($first !== $offset || $last < $first) {
         return 0;
      }

      $wanted = $length !== null ? $offset + $length - 1 : null;
      // ?: The object's size is unknown to the store: the window may only end early
      if ($matches[3] === '*') {
         return $wanted !== null && $last > $wanted ? 0 : $last - $first + 1;
      }

      $end = (int) $matches[3] - 1;
      $expected = $wanted === null || $wanted > $end ? $end : $wanted;

      // :
      return $last === $expected ? $last - $first + 1 : 0;
   }

   /**
    * The ETag S3 gives the object a Multipart Upload of these parts completes into —
    * `"<MD5 of the parts' binary MD5s, in part order>-<number of parts>"` — to tell, when the
    * answer to a completion was lost, whether the object at the key is the one these parts make.
    * Null when it cannot be computed: no parts, more than `PARTS`, or a part ETag that is not 32
    * hex digits. A store whose part ETags are not the parts' MD5s (SSE-KMS, SSE-C, some S3-compatible
    * stores) may still give 32 hex digits: the fold is then computed, and the object's ETag never
    * matches it.
    *
    * @param array<int|string,mixed> $parts Keyed by part number, as `Writer::join()` takes them
    *    (`[number => ['etag' => ...]]`).
    */
   public static function fold (array $parts): null|string
   {
      // ?
      if ($parts === [] || count($parts) > self::PARTS) {
         return null;
      }
      ksort($parts);

      $digests = '';
      foreach ($parts as $part) {
         $value = is_array($part) === true ? ($part['etag'] ?? null) : null;
         $etag = is_string($value) === true ? strtolower(trim($value, '" ')) : '';
         // ?
         if (preg_match('/^[0-9a-f]{32}$/D', $etag) !== 1) {
            return null;
         }
         $digests .= (string) hex2bin($etag);
      }

      // :
      return '"' . md5($digests) . '-' . count($parts) . '"';
   }

   /**
    * Parse an S3 XML answer; null when it is not one. No network access, and no document type:
    * an S3 answer never declares one, so refusing it rules out entity expansion.
    */
   public static function decode (string $body): null|SimpleXMLElement
   {
      // ! Drop the default (and any prefixed) xmlns so element access works uniformly across S3
      //   and S3-compatible providers. Everything below judges `$clean` — the string that is
      //   parsed — never `$body`: the removal itself can join a keyword (`<!DOC xmlns=""TYPE`)
      $clean = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $body) ?? $body;

      // ? An S3 answer is UTF-8. Another encoding — NUL bytes (UTF-16/32), a declared charset, a
      //   first byte that is not `<` — could hide a DOCTYPE from the check below
      $head = ltrim(str_starts_with($clean, "\xEF\xBB\xBF") === true ? substr($clean, 3) : $clean);
      if (
         $head === ''
         || $head[0] !== '<'
         || strpos($clean, "\x00") !== false
         || preg_match('/^<\?xml[^>]*\bencoding\s*=\s*["\'](?!utf-8["\'])/i', $head) === 1
      ) {
         return null;
      }
      // ?
      if (stripos($clean, '<!DOCTYPE') !== false || stripos($clean, '<!ENTITY') !== false) {
         return null;
      }

      $previous = libxml_use_internal_errors(true);
      $XML = simplexml_load_string($clean, SimpleXMLElement::class, LIBXML_NONET);
      libxml_clear_errors();
      libxml_use_internal_errors($previous);

      // :
      return $XML === false ? null : $XML;
   }

   // ---

   /**
    * The failure of an answer outside its operation's success set.
    */
   private static function refuse (int $status, string $body, string $subject): Failure
   {
      $kind = $status >= 200 && $status < 300 && strpos($body, '<Error') === false
         ? Kinds::Protocol
         : Kinds::Refusal;

      // :
      return new Failure($status, self::code($body), $kind, "{$subject}: HTTP {$status}");
   }

   /**
    * The `<Code>` of an S3 `<Error>` document; `''` when the body is not one, or its code does
    * not have the form of an S3 error code (letters, digits and dots, at most 64).
    */
   private static function code (string $body): string
   {
      // ?
      if (strpos($body, '<Error') === false) {
         return '';
      }
      $XML = self::decode($body);
      $code = $XML !== null && $XML->getName() === 'Error' ? (string) $XML->Code : '';

      // :
      return preg_match('/^[A-Za-z][A-Za-z0-9.]{0,63}$/D', $code) === 1 ? $code : '';
   }
}

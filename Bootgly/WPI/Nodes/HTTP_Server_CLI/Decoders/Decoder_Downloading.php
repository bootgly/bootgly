<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use const BOOTGLY_STORAGE_DIR;
use const PHP_MAXPATHLEN;
use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_NO_FILE;
use function array_walk_recursive;
use function basename;
use function bin2hex;
use function chmod;
use function count;
use function disk_free_space;
use function explode;
use function fclose;
use function fopen;
use function fstat;
use function fwrite;
use function ini_get;
use function intdiv;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function ltrim;
use function min;
use function mkdir;
use function parse_str;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function strtolower;
use function substr;
use function substr_count;
use function time;
use function trim;
use function unlink;
use function urlencode;
use Throwable;

use const Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


class Decoder_Downloading extends Decoders implements Disconnecting
{
   /**
    * Conservative allowance, in bytes, for ONE node of a map `parse_str()`
    * materializes. Multipart names keep PHP bracket semantics, so a single
    * part named `a[b][c]` becomes a chain of nested arrays — the cost is per
    * NODE, not per part, and it is dominated by the hashtable each node
    * allocates rather than by the characters of the name.
    *
    * Measured at 409-419 bytes per node for the containers alone, but the
    * decode call that produces them carries scratch the map terms cannot see —
    * ~748 bytes per node once that is included. 1 KiB keeps the bound above
    * every measurement and scales with the shape instead of inflating a flat
    * margin, which would put small-budget deployments out of reach.
    */
   private const int NODE_OVERHEAD = 1024;
   /**
    * Conservative allowance, in bytes, for ONE key/value slot inside an
    * existing hashtable — the bucket plus the string headers around it.
    *
    * Measured at up to ~77 bytes per retained field entry on PHP 8.4 once the
    * packed-array growth steps are amortized; 96 stays above that.
    */
   private const int ENTRY_OVERHEAD = 96;
   /**
    * Conservative FIXED margin, in bytes, charged once per non-empty map —
    * whether retained here or produced at completion.
    *
    * Per-byte and per-entry terms alone go negative on small inputs: the root
    * hashtable, the string headers, the parser's own scratch and the
    * allocator's rounding do not scale with the payload. Measured worst-case
    * floors were ~1.4 KiB for a produced map and ~5.0 KiB for retained
    * containers; 16 KiB keeps a wide margin across shapes and PHP builds, and
    * costs at most a fraction of a percent of the worker ceiling.
    */
   private const int MAP_OVERHEAD = 16 * 1024;
   /**
    * Slots in one `$files` record: `name`, `tmp_name`, `size`, `error` and
    * `type`. Fixed by the literal that builds the record; a sixth key belongs
    * here too, or empty parts stop being priced for what they really cost.
    */
   private const int RECORD_SLOTS = 5;
   /**
    * Zend's small allocation size classes. A request that fits none of them
    * is served from whole 4 KiB pages instead. Mirrors `ZEND_MM_BINS` so a
    * retained string can be priced for the block it occupies rather than for
    * the bytes it carries.
    *
    */
   /**
    * Octets `urlencode()` leaves at one byte — the unreserved set plus the
    * space it maps to `+`.
    */
   private const string UNRESERVED =
      'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.- ';
   /** @var array<int,int> */
   private const array BLOCKS = [
      8, 16, 24, 32, 40, 48, 56, 64,
      80, 96, 112, 128, 160, 192, 224, 256,
      320, 384, 448, 512, 640, 768, 896, 1024,
      1280, 1536, 1792, 2048, 2560, 3072,
   ];

   // * Config
   private string $boundary;

   // * Data
   //   Owning Request, bound at the decoder install site in Request::decode():
   //   body continuations must never resolve the worker-global Request —
   //   another connection may replace or claim it between transport reads.
   public Request $Request;
   private string $tailBuffer = '';
   //   Whether tail byte zero is still a proved multipart line start. It is
   //   true at absolute body offset zero and for a candidate already found
   //   after CRLF; generic preamble truncation revokes that provenance.
   private bool $tailAnchored = true;
   private string $headerBuffer = '';
   private string $fieldBuffer = '';
   // Raw values are retained once; the encoded string carries only each
   // field name and its index so parse_str() can preserve bracket semantics.
   /** @var array<int,string> */
   private array $fields = [];
   private string $fieldsEncoded = '';
   /** @var array<int,array<string,bool|int|string>> */
   private array $files = [];
   /** @var array<int,string> */
   private array $filesKeys = [];
   private string $currentFieldName = '';
   //   A tempfile exists before its metadata record can be committed. Keep
   //   that narrow transaction window decoder-owned so abort()/destruction can
   //   always reclaim the exact path.
   private string $pendingFile = '';
   /** @var resource|null */
   private $fileHandler = null;

   // * Metadata
   //   Share of the worker-wide unfinished-body budget held by this decoder.
   //   File parts stream to disk under `Downloads`, but TEXT parts are kept in
   //   memory (`$fieldBuffer` plus the completed values in `$fields`), so they
   //   belong to the same in-memory ceiling the other HTTP/1 body decoders draw
   //   on. `$fieldsSize` is their exact retained total.
   public protected(set) Bodies $Bodies;
   private int $decoded = 0;
   private int $read = 0;
   private int $state = self::STATE_BOUNDARY_START;
   private int $downloaded = 0;
   private int $currentFileSize = 0;
   private int $currentFieldSize = 0;
   private int $fieldsSize = 0;
   //   Heap the COMPLETED field values occupy, in allocator blocks rather than
   //   logical bytes. `$fieldsSize` stays logical because it enforces the
   //   per-request `maxBodySize` policy cap; this one prices memory.
   private int $fieldsBlocks = 0;
   //   Heap the keys `parse_str()` will DECODE BACK occupy, in allocator
   //   blocks. The encoded aggregate cannot bound them: decoding never expands
   //   the bytes, but a 4,072-byte key still crosses the 4 KiB size class into
   //   two pages, so the logical length under-prices it by a whole page.
   private int $keysBlocks = 0;
   //   Ceilings `parse_str()` enforces by silently discarding input. Read once
   //   per request so the hot path never touches the INI table.
   private int $variables = 0;
   private int $nesting = 0;
   //   Map nodes `parse_str()` will materialize at completion: one per part
   //   plus one per bracket level in its name. Counted from the RAW names as
   //   they are accepted, so nesting is priced before the expansion exists.
   private int $nodes = 0;
   //   Bytes of retained per-file metadata (upload key plus the client-supplied
   //   strings in each entry). The only array term `measure()` cannot read from
   //   a live string, kept as a counter so pricing stays O(1); it grows at the
   //   single site that appends to `$filesKeys`/`$files`.
   private int $filesSize = 0;
   private int $fieldsCount = 0;
   private int $filesCount = 0;
   private int $bytesSinceDiskCheck = 0;
   private bool $parsed = false;
   private bool $finished = false;
   private bool $aborted = false;

   // # States
   private const int STATE_BOUNDARY_START  = 0;
   private const int STATE_PART_HEADER     = 1;
   private const int STATE_PART_BODY_FILE  = 2;
   private const int STATE_PART_BODY_FIELD = 3;
   // # Multipart delimiter classification
   //   A non-negative result is the first byte after a valid next-part line.
   private const int DELIMITER_INVALID    = -1;
   private const int DELIMITER_INCOMPLETE = -2;
   private const int DELIMITER_CLOSED     = -3;
   private const string DELIMITER_PADDING = " \t";


   public function init (string $boundary): void
   {
      // * Config
      $this->boundary = $boundary;

      // * Metadata
      $this->Bodies = new Bodies;
      // ! What `parse_str()` will ACTUALLY materialize at completion. Beyond
      //   these it silently drops variables — the whole point of shaping the
      //   maps is lost, and the ledger has already paid for them.
      $this->variables = (int) ini_get('max_input_vars');
      $this->nesting = (int) ini_get('max_input_nesting_level');

      // * Data
      $this->tailBuffer = '';
      $this->tailAnchored = true;
      $this->headerBuffer = '';
      $this->fieldBuffer = '';
      $this->fields = [];
      $this->fieldsEncoded = '';
      $this->files = [];
      $this->filesKeys = [];
      $this->currentFieldName = '';
      $this->pendingFile = '';
      $this->fileHandler = null;

      // * Metadata
      $this->decoded = time();
      $this->read = 0;
      $this->state = self::STATE_BOUNDARY_START;
      $this->downloaded = 0;
      $this->currentFileSize = 0;
      $this->currentFieldSize = 0;
      $this->fieldsSize = 0;
      $this->fieldsBlocks = 0;
      $this->keysBlocks = 0;
      $this->filesSize = 0;
      $this->nodes = 0;
      $this->fieldsCount = 0;
      $this->filesCount = 0;
      $this->bytesSinceDiskCheck = 0;
      $this->parsed = false;
      $this->finished = false;
      $this->aborted = false;
   }

   /**
    * Feed initial body data from the first buffer into the streaming decoder.
    */
   public function feed (string $data): void
   {
      $length = strlen($data);
      $buffer = $this->tailBuffer . $data;
      $boundaryLen = strlen($this->boundary);
      $position = self::locate(
         $buffer,
         $this->boundary,
         $this->tailAnchored
      );

      if ($position === false) {
         // ! The initial transport event follows the same bounded-preamble
         //   rule as continuation reads. Preserve CRLF plus any boundary
         //   prefix that may complete in the next event.
         $tailLength = $boundaryLen + 2;
         if (strlen($buffer) > $tailLength) {
            $this->tailBuffer = substr($buffer, -$tailLength);
            $this->tailAnchored = false;
         }
         else {
            $this->tailBuffer = $buffer;
         }
      }
      else {
         // @ A boundary is already present: discard only its preamble. Bytes
         //   after the match may contain part headers/body and must be parsed
         //   by the normal state machine on the next read.
         $this->tailBuffer = substr($buffer, $position);
         $this->tailAnchored = true;
      }

      $this->downloaded += $length;
      $this->read = $this->downloaded;
   }

   /**
    * Heap a retained string of `$bytes` payload actually occupies.
    *
    * Zend serves any block over 3 KiB from whole 4 KiB pages, and a
    * `zend_string` needs its 24-byte header plus a NUL on top of the payload.
    * So a 4,072-byte value occupies 8,192 bytes — charging its logical length
    * lets a peer pick exactly that cliff and hold ~2x what the ledger admitted
    * (measured 8,491,664 bytes held against 4,317,936 admitted over 1,024
    * fields, cross-checked against VmRSS). Below the page threshold the
    * small-bin rounding is a few dozen bytes and the fixed margins cover it.
    */
   private static function block (int $bytes): int
   {
      // ! zend_string is its 24-byte header plus the payload and a NUL, and
      //   the chunk's own bookkeeping rides along — which is what pushes a
      //   request sitting just under a boundary into the next class.
      $needed = $bytes + 25 + 32;

      // ? Above the largest bin the allocator serves whole pages.
      if ($needed > self::BLOCKS[29]) {
         return (intdiv($needed, 4096) + 1) * 4096;
      }

      // @@ Otherwise the smallest size class that fits
      foreach (self::BLOCKS as $size) {
         if ($needed <= $size) {
            return $size;
         }
      }

      // :
      return self::BLOCKS[29];
   }

   /**
    * Every byte this decoder still holds in memory for an unfinished request.
    *
    * Derived from live state rather than from a single running counter: an
    * accounting that tracks only the field VALUES silently excludes the wire
    * carried between reads, the part header, and the attacker-controlled names
    * kept beside the values — each of which was, in turn, a real bypass. A new
    * buffer added to this class is priced the moment it is listed here.
    *
    * `$fieldsSize` already covers both the current value and the completed
    * ones, so `$fieldBuffer` must NOT be added again.
    */
   private function measure (): int
   {
      // ! The CONTAINERS holding those strings count too. Pricing only bytes
      //   lets a peer retain hundreds of empty file parts — each record is its
      //   own hashtable — while the ledger sees almost nothing. They live for
      //   as long as the request is unfinished, so they belong here and not
      //   only in the completion projection, which a peer reaches by never
      //   sending the terminal boundary.
      //   Each non-empty container also costs a FIXED amount that scales with
      //   nothing — its root hashtable, the allocator's rounding, the string
      //   headers — so a purely per-entry price goes short on small inputs.
      $containers = ((count($this->fields) + count($this->filesKeys))
            * self::ENTRY_OVERHEAD)
         + (count($this->files)
            * (self::NODE_OVERHEAD + (self::RECORD_SLOTS * self::ENTRY_OVERHEAD)))
         + ($this->fields === [] ? 0 : self::MAP_OVERHEAD)
         + ($this->filesKeys === [] ? 0 : self::MAP_OVERHEAD)
         + ($this->files === [] ? 0 : self::MAP_OVERHEAD);

      return $this->fieldsBlocks
         + self::block(strlen($this->fieldBuffer))
         + $this->filesSize
         + self::block(strlen($this->tailBuffer))
         + self::block(strlen($this->headerBuffer))
         + self::block(strlen($this->fieldsEncoded))
         + self::block(strlen($this->currentFieldName))
         + $containers;
   }

   /**
    * Heap the components of one multipart NAME occupy once `parse_str()` has
    * decoded it back.
    *
    * A structured name is not one string on the other side: `a[b][c]` becomes
    * a separate key string per bracket segment, and each of them crosses the
    * allocator's size classes on its own. Charging one block for the whole
    * name under-prices `4072[4072]` by an entire page per segment.
    */
   /**
    * Length `urlencode()` would produce for `$name`, computed WITHOUT producing
    * it — the reservation has to precede that allocation, and a blanket 3x
    * bound would over-charge every plain name by triple.
    *
    * `urlencode()` keeps `[A-Za-z0-9_.-]`, maps a space to `+` (still one
    * byte) and expands everything else to `%XX`.
    */
   private static function expand (string $name): int
   {
      // !
      $length = strlen($name);
      $kept = 0;
      $offset = 0;

      // @@ Alternate runs of kept and expanded octets; strspn/strcspn scan
      //    in place, so nothing is allocated here.
      while ($offset < $length) {
         $run = strspn($name, self::UNRESERVED, $offset);

         if ($run > 0) {
            $kept += $run;
            $offset += $run;

            continue;
         }

         $offset += strcspn($name, self::UNRESERVED, $offset);
      }

      // :
      return $kept + (($length - $kept) * 3);
   }

   private static function weigh (string $name): int
   {
      // ! Scanned, never split: `substr()`/`explode()` would allocate the very
      //   strings this is supposed to price BEFORE the reservation clears.
      $length = strlen($name);
      $bracket = strpos($name, '[');

      // ?: A flat name is a single key
      if ($bracket === false) {
         return self::block($length);
      }

      // ! The base key, then one per `[...]` segment
      $blocks = self::block($bracket);
      $offset = $bracket + 1;

      // @@
      while ($offset <= $length) {
         $close = strpos($name, ']', $offset);
         $next = strpos($name, '[', $offset);

         if ($close === false) {
            $close = $next === false ? $length : $next;
         }
         else if ($next !== false && $next < $close) {
            $close = $next;
         }

         $blocks += self::block($close - $offset);

         if ($next === false) {
            break;
         }
         $offset = $next + 1;
      }

      // :
      return $blocks;
   }

   /**
    * Locate the first dash-boundary that starts a multipart line.
    *
    * The initial state may contain a preamble, so searching for the bare token
    * is insufficient: an inline occurrence is ordinary preamble text. The
    * body states search for CRLF plus the token and therefore need no second
    * anchor check.
    */
   private static function locate (
      string $data,
      string $boundary,
      bool $anchored
   ): int|false
   {
      if ($anchored && str_starts_with($data, $boundary)) {
         return 0;
      }

      // ? Frame has already excluded CR/LF from the boundary value. Searching
      //   the anchored needle once in C skips every inline preamble occurrence
      //   without an attacker-amplifiable PHP loop.
      $position = strpos($data, "\r\n" . $boundary);

      return $position === false ? false : $position + 2;
   }

   /**
    * Retain a constant-size canonical prefix for an incomplete delimiter.
    *
    * RFC transport padding is unbounded. Keeping its complete run would copy
    * and rescan an attacker-growing string on every transport fragment. Once
    * classify() has proved every available suffix byte valid but incomplete,
    * one SP preserves the padding phase and one CR preserves a split CRLF.
    */
   private static function retain (
      string $data,
      int $position,
      int $offset,
      int $length
   ): string
   {
      $candidate = substr($data, $position, $offset - $position);
      if ($offset >= $length) {
         return $candidate;
      }

      $first = $data[$offset];
      if ($first === '-') {
         $candidate .= '-';
         $offset++;
         if ($offset >= $length) {
            return $candidate;
         }

         // ? classify() only returns INCOMPLETE here after proving the second
         //   close hyphen. Preserve that phase without retaining its padding.
         $candidate .= '-';
         $offset++;
         if ($offset >= $length) {
            return $candidate;
         }
      }

      // ? An incomplete suffix beginning with padding can contain only more
      //   padding and, optionally, one terminal CR already validated above.
      if ($data[$offset] === ' ' || $data[$offset] === "\t") {
         $candidate .= ' ';
      }

      return $candidate . ($data[$length - 1] === "\r" ? "\r" : '');
   }

   /**
    * Classify the bytes immediately following one complete dash-boundary.
    *
    * A valid next-part delimiter is optional SP/HTAB transport padding plus
    * CRLF. A closing delimiter adds `--` before that padding and may end with
    * the declared body instead of CRLF. The common zero-padding path uses
    * direct byte comparisons; strspn() runs only when padding is present.
    *
    * @return int A non-negative next-part offset or DELIMITER_* state.
    */
   private static function classify (
      string $data,
      int $offset,
      int $length,
      bool $complete
   ): int
   {
      if ($offset >= $length) {
         return $complete
            ? self::DELIMITER_INVALID
            : self::DELIMITER_INCOMPLETE;
      }

      // ? The overwhelmingly common next-part suffix is immediate CRLF.
      //   Return from that two-byte path before initializing close/padding
      //   state or calling a scanning primitive.
      $first = $data[$offset];
      if ($first === "\r") {
         if ($offset + 1 >= $length) {
            return $complete
               ? self::DELIMITER_INVALID
               : self::DELIMITER_INCOMPLETE;
         }

         return $data[$offset + 1] === "\n"
            ? $offset + 2
            : self::DELIMITER_INVALID;
      }

      $closing = false;
      if ($first === '-') {
         // ?: A leading hyphen can only begin the closing `--`. One trailing
         //   hyphen is incomplete until another transport read proves otherwise.
         if ($offset + 1 >= $length) {
            return $complete
               ? self::DELIMITER_INVALID
               : self::DELIMITER_INCOMPLETE;
         }
         if ($data[$offset + 1] !== '-') {
            return self::DELIMITER_INVALID;
         }

         $closing = true;
         $offset += 2;
      }
      else if ($first !== ' ' && $first !== "\t") {
         return self::DELIMITER_INVALID;
      }

      // ? RFC 2046 transport-padding. Keep the zero-padding hot path in PHP
      //   above; scan a present padding run once in C.
      if (
         $offset < $length
         && ($data[$offset] === ' ' || $data[$offset] === "\t")
      ) {
         $offset += strspn($data, self::DELIMITER_PADDING, $offset);
      }

      if ($offset >= $length) {
         if ($closing && $complete) {
            return self::DELIMITER_CLOSED;
         }

         return $complete
            ? self::DELIMITER_INVALID
            : self::DELIMITER_INCOMPLETE;
      }

      if ($data[$offset] !== "\r") {
         return self::DELIMITER_INVALID;
      }
      if ($offset + 1 >= $length) {
         return $complete
            ? self::DELIMITER_INVALID
            : self::DELIMITER_INCOMPLETE;
      }
      if ($data[$offset + 1] !== "\n") {
         return self::DELIMITER_INVALID;
      }

      return $closing
         ? self::DELIMITER_CLOSED
         : $offset + 2;
   }

   /**
    * Re-price this decoder against the worker-wide budget. False means the
    * current footprint does not fit and the caller must reject the request.
    */
   public function charge (): bool
   {
      return $this->Bodies->reserve($this->measure());
   }

   /**
    * Abort an in-flight upload when its transport connection closes.
    */
   public function disconnect (): void
   {
      $this->abort();
   }

   public function __destruct ()
   {
      $this->abort();
   }

   /**
    * Best-effort removal for one decoder-owned tempfile.
    *
    * This is deliberately no-throw: application error handlers still receive
    * warnings hidden with `@` and may promote them to exceptions. Disk
    * accounting is released only after the exact path is proved absent.
    */
   private function remove (string $path): void
   {
      if ($path === '') {
         return;
      }

      $absent = false;
      try {
         $absent = ! is_file($path) || @unlink($path);
      }
      catch (Throwable) {
         try {
            $absent = ! is_file($path);
         }
         catch (Throwable) {}
      }

      if ($absent) {
         try {
            Downloads::discard($path);
         }
         catch (Throwable) {}
      }
   }

   public function decode (Packages $Package, string $buffer, int $size): States
   {
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      $Request = $this->Request;
      $Body = $Request->Body;

      // ! Reject helper: centralize cleanup and rejection logic
      $reject = function (string $raw) use ($Package, $Request): void {
         $this->abort();

         if ($Package->rejected) {
            return;
         }

         $Request->Body->waiting = false;

         if ($Package instanceof TCP_Packages) {
            $Package->reject($raw); // sets $Package->rejected = true
         } else {
            $Package->rejected = true;
         }
      };
      // ! Append field data with size checks, centralizing both boundary and
      //   non-boundary accumulation paths.
      $appendField = function (string $chunk) use ($reject): bool {
         if ($chunk === '') {
            return true;
         }

         $length = strlen($chunk);
         if ($this->currentFieldSize + $length > Server\Request::$maxMultipartFieldSize) {
            $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return false;
         }
         // ? Text parts must not inherit the 500 MiB file-body allowance.
         //   Bound their raw aggregate to the normal in-memory body policy.
         if ($this->fieldsSize + $length > Server\Request::$maxBodySize) {
            $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return false;
         }
         // ? Both caps above bound THIS request. Text parts are held in memory
         //   for as long as the multipart body stays unfinished, so their sum
         //   across connections draws on the same worker ceiling the other
         //   HTTP/1 body decoders use — file parts do not, they go to disk.
         // ! `measure()` already owns the old string. The append may allocate
         //   the complete new destination before releasing that old block, so
         //   reserve the full destination rather than assuming an in-place
         //   realloc. `$chunk` is the already-present, transport-read-bounded
         //   source; the worker ledger prices the additional retained copy.
         $held = strlen($this->fieldBuffer);
         $growth = self::block($held + $length);

         if ($this->Bodies->reserve($this->measure() + $growth) === false) {
            $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
            return false;
         }

         $this->fieldBuffer .= $chunk;
         $this->currentFieldSize += $length;
         $this->fieldsSize += $length;

         return true;
      };

      // ?: Check if Request Body is waiting data
      if (! $Body->waiting) {
         $Package->Decoder = null;
         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // ?: Valid HTTP Client Body Timeout
      $elapsed = time() - $this->decoded;
      if ($elapsed >= 60 && $this->read === $this->downloaded) {
         $reject("HTTP/1.1 408 Request Timeout\r\n\r\n");
         $Package->Decoder = null;
         $Package->consumed = 0;
         return States::Rejected;
      }

      // @ Consume only bytes that belong to this Content-Length body. Initial
      //   bytes accepted with the request head were recorded by feed(); the
      //   transport cursor for this call must include only the remaining body
      //   bytes, leaving any pipelined request untouched.
      $length = $Body->length ?? ($this->downloaded + $size);
      $remaining = $length - $this->downloaded;
      $consumed = $remaining > 0 ? min($size, $remaining) : 0;
      $this->downloaded += $consumed;
      $this->read = $this->downloaded;

      // @ Prepend tail buffer from previous chunk
      $data = $this->tailBuffer;
      if ($consumed > 0) {
         $data .= $consumed === $size
            ? $buffer
            : substr($buffer, 0, $consumed);
      }
      $this->tailBuffer = '';
      $bodyComplete = $Body->length !== null
         && $this->downloaded >= $Body->length;

      // ? A terminal boundary may precede an allowed multipart epilogue.
      //   Once parsed, consume the remaining declared body without feeding
      //   epilogue bytes back into the part state machine.
      if ($this->parsed) {
         $data = '';
      }

      // @@ Process data through state machine
      while ($data !== '') {
         switch ($this->state) {
            case self::STATE_BOUNDARY_START:
               $boundary = $this->boundary;
               $boundaryLen = strlen($boundary);
               $dataLength = strlen($data);

               // @ The first boundary may follow a preamble, but only at the
               //   beginning of a multipart line.
               $pos = self::locate(
                  $data,
                  $boundary,
                  $this->tailAnchored
               );
               if ($pos === false) {
                  // ! Discard the already-scanned multipart preamble. Only a
                  //   boundary-sized suffix can participate in a boundary
                  //   split across the next transport read.
                  $tailLength = $boundaryLen + 2;
                  if ($dataLength > $tailLength) {
                     $this->tailBuffer = substr($data, -$tailLength);
                     $this->tailAnchored = false;
                  }
                  else {
                     $this->tailBuffer = $data;
                  }
                  $data = null;
                  break;
               }

               $afterBoundary = $pos + $boundaryLen;
               $delimiter = self::classify(
                  $data,
                  $afterBoundary,
                  $dataLength,
                  $bodyComplete
               );

               // ! Fail closed on one malformed line-start candidate. The
               //   sender chose this boundary and MUST keep it out of part
               //   lines; rejection is both cheaper and safer than recovery.
               if ($delimiter === self::DELIMITER_INVALID) {
                  $reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $data = '';
                  break;
               }
               if ($delimiter === self::DELIMITER_INCOMPLETE) {
                  // @ Retain a canonical candidate, never the discarded
                  //   preamble or an attacker-growing transport-padding run.
                  $this->tailBuffer = self::retain(
                     $data,
                     $pos,
                     $afterBoundary,
                     $dataLength
                  );
                  $this->tailAnchored = true;
                  $data = null;
                  break;
               }
               if ($delimiter === self::DELIMITER_CLOSED) {
                  // @ Retain ownership until every declared body byte arrives;
                  //   later bytes are the allowed multipart epilogue.
                  $this->parsed = true;
                  $data = '';
                  break;
               }

               $this->state = self::STATE_PART_HEADER;
               $this->headerBuffer = '';

               $data = substr($data, $delimiter);
               break;

            case self::STATE_PART_HEADER:
               $this->headerBuffer .= $data;

               // @ Search for end of headers (\r\n\r\n)
               $headerEnd = strpos($this->headerBuffer, "\r\n\r\n");
               if ($headerEnd === false) {
                  if (strlen($this->headerBuffer) > Server\Request::$maxMultipartHeaderSize) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }

                  // @ Need more data
                  $data = null;
                  break;
               }
               if ($headerEnd > Server\Request::$maxMultipartHeaderSize) {
                  $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $data = '';
                  break;
               }

               // @ Extract headers
               $headersRaw = substr($this->headerBuffer, 0, $headerEnd);
               $remainder = substr($this->headerBuffer, $headerEnd + 4);
               $this->headerBuffer = '';

               // @ Parse headers
               $contentLines = explode("\r\n", trim($headersRaw));

               $uploadKey = false;
               $file = [];
               $isFile = false;
               $fieldName = '';

               foreach ($contentLines as $contentLine) {
                  if (! strpos($contentLine, ': ')) {
                     continue;
                  }

                  @[$key, $value] = explode(': ', $contentLine, 2);

                  switch (strtolower($key)) {
                     case 'content-disposition':
                        // @ File
                        if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                           $isFile = true;
                           $uploadKey = $match[1];

                           // ? An empty RAW filename is a browser's "no file
                           //   chosen" part — commit the no-file record PHP's
                           //   rfc1867 parser produces for the same bytes. The
                           //   pre-set error keeps every filesystem step below
                           //   behind its `error === 0` guard, so no temp
                           //   inode is ever created for it. Only the raw
                           //   value discriminates: a non-empty filename that
                           //   SANITIZES down to empty still takes the
                           //   `'upload'` placeholder path.
                           if ($match[2] === '') {
                              $file = [
                                 'name' => '',
                                 'tmp_name' => '',
                                 'size' => 0,
                                 'error' => UPLOAD_ERR_NO_FILE,
                                 'type' => '',
                              ];
                              break;
                           }

                           // ! Sanitize filename: strip directory traversal and restrict to safe characters
                           $rawFilename = basename($match[2]);
                           $safeFilename = (string) preg_replace('/[^\w.\- ]/', '_', $rawFilename);
                           // ! Hidden-dot hardening: reject leading dots like `.htaccess`
                           $safeFilename = ltrim($safeFilename, ". \t");
                           if ($safeFilename === '') {
                              $safeFilename = 'upload';
                           }
                           $file = [
                              'name' => $safeFilename,
                              'tmp_name' => '',
                              'size' => 0,
                              'error' => 0,
                              'type' => '',
                           ];
                        }
                        // @ Text field
                        else if (preg_match('/name="(.*?)"$/', $value, $match)) {
                           $fieldName = $match[1];
                        }

                        break;
                     case 'content-type':
                        // ? A record already carrying an error keeps `type`
                        //   empty — $_FILES parity for the no-file part.
                        if (($file['error'] ?? 0) === 0) {
                           $file['type'] = trim($value);
                        }
                        break;
                  }
               }

               if ($isFile && $uploadKey !== false) {
                  if ($this->filesCount >= Server\Request::$maxMultipartFiles) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }
                  $this->filesCount++;

                  // ? Same ceilings on the file side of the same map.
                  if (
                     $this->fieldsCount + $this->filesCount > $this->variables
                     || substr_count($uploadKey, '[') > $this->nesting
                  ) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }

                  $tempUploadedDir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';

                  // ? Price the record BEFORE retaining it: the key, the
                  //   client-supplied strings, every name component
                  //   `parse_str()` will decode back, and the nodes it makes.
                  //   `tmp_name` does not exist yet, so reserve its platform
                  //   maximum before exclusive creation can touch the disk.
                  $keys = self::weigh($uploadKey);
                  $nodes = 1 + substr_count($uploadKey, '[');
                  $growth = self::block(strlen($uploadKey)) + $keys
                     + ($nodes * self::NODE_OVERHEAD)
                     + self::NODE_OVERHEAD
                     + (self::RECORD_SLOTS * self::ENTRY_OVERHEAD)
                     // ! Both outer packed arrays may replace their complete
                     //   storage on `[]`. `measure()` owns the old records;
                     //   reserve both full destinations while those remain
                     //   live. This also includes the new filesKeys slot.
                     + ((count($this->filesKeys) + 1) * self::ENTRY_OVERHEAD)
                     + ((count($this->files) + 1) * self::ENTRY_OVERHEAD);
                  foreach ($file as $detailName => $detail) {
                     if (is_string($detail)) {
                        $growth += self::block(
                           $detailName === 'tmp_name'
                              ? PHP_MAXPATHLEN
                              : strlen($detail)
                        );
                     }
                  }
                  if ($this->filesKeys === []) {
                     $growth += self::MAP_OVERHEAD;
                  }
                  if ($this->files === []) {
                     $growth += self::MAP_OVERHEAD;
                  }

                  if ($this->Bodies->reserve($this->measure() + $growth) === false) {
                     $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
                     $data = '';
                     break;
                  }

                  // @ Only after the complete record fits may this request
                  //   touch the filesystem. Choose the path ourselves and use
                  //   atomic exclusive creation: unlike tempnam(), the local
                  //   `x` open either returns our descriptor or creates
                  //   nothing, so a fallback warning cannot hide a created
                  //   path from decoder ownership.
                  try {
                     // ? A record that already carries an error — the no-file
                     //   part above — skips every filesystem step: the two
                     //   guards below already read the same condition.
                     if (
                        ($file['error'] ?? 0) === 0
                        && ! is_dir($tempUploadedDir)
                        && ! mkdir($tempUploadedDir, 0700, true)
                        && ! is_dir($tempUploadedDir)
                     ) {
                        $file['error'] = UPLOAD_ERR_CANT_WRITE;
                     }

                     if (($file['error'] ?? 0) === 0) {
                        $freeSpace = disk_free_space($tempUploadedDir);
                        if ($freeSpace !== false && $freeSpace < 1048576) {
                           $file['error'] = UPLOAD_ERR_CANT_WRITE;
                        }
                     }

                     if (($file['error'] ?? 0) === 0) {
                        $tempFile = $tempUploadedDir
                           . bin2hex(random_bytes(16));

                        $handle = fopen($tempFile, 'x+b');
                        if ($handle === false) {
                           $file['error'] = UPLOAD_ERR_CANT_WRITE;
                        }
                        else {
                           // ! `x` either returns the descriptor for the file
                           //   this call exclusively created or creates
                           //   nothing. Publish ownership only after that
                           //   atomic result, never for a colliding candidate.
                           $this->pendingFile = $tempFile;
                           $file['tmp_name'] = $tempFile;
                           $this->fileHandler = $handle;

                           // ? Match tempnam()'s private-file guarantee even
                           //   when the worker's process umask is permissive.
                           if (! chmod($tempFile, 0600)) {
                              $file['error'] = UPLOAD_ERR_CANT_WRITE;
                           }
                        }
                     }
                  }
                  catch (Throwable) {
                     $file['error'] = UPLOAD_ERR_CANT_WRITE;
                  }

                  if (
                     ($file['error'] ?? 0) !== 0
                     && $this->fileHandler !== null
                  ) {
                     try {
                        fclose($this->fileHandler);
                     }
                     catch (Throwable) {}

                     $this->fileHandler = null;
                  }

                  $this->currentFileSize = 0;
                  $this->bytesSinceDiskCheck = 0;

                  // @ Commit the metadata record before releasing pending
                  //   ownership. abort() can now reach this exact path through
                  //   `$files` on every later refusal.
                  $this->files[] = $file; // @ Index will be updated with size at close
                  $this->filesKeys[] = $uploadKey;
                  $this->pendingFile = '';

                  // ? The entry carries client-supplied strings (upload key,
                  //   filename, content type) that stay in memory for the whole
                  //   request, so they are priced like any other retention.
                  // ? One node for the entry plus one per bracket level the
                  //   name will expand into.
                  $this->nodes += $nodes;
                  $this->keysBlocks += $keys;
                  //   Priced by the block each string occupies, like every
                  //   other retained string — a key just over a page boundary
                  //   costs two pages whatever its length says.
                  $this->filesSize += self::block(strlen($uploadKey));
                  foreach ($file as $detail) {
                     if (is_string($detail)) {
                        $this->filesSize += self::block(strlen($detail));
                     }
                  }
                  if ($this->charge() === false) {
                     $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
                     $data = '';
                     break;
                  }

                  $this->state = self::STATE_PART_BODY_FILE;
               }
               else if ($fieldName !== '') {
                  if ($this->fieldsCount >= Server\Request::$maxMultipartFields) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }
                  $this->fieldsCount++;

                  // ? `parse_str()` DROPS whatever exceeds these, so accepting
                  //   the part would silently lose it while the ledger paid for
                  //   it. Refuse instead of shaping a map that cannot hold it.
                  if (
                     $this->fieldsCount + $this->filesCount > $this->variables
                     || substr_count($fieldName, '[') > $this->nesting
                  ) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }

                  $this->currentFieldName = $fieldName;
                  $this->fieldBuffer = '';
                  $this->currentFieldSize = 0;
                  $this->state = self::STATE_PART_BODY_FIELD;
               }

               $data = $remainder;
               break;

            case self::STATE_PART_BODY_FILE:
               $boundary = "\r\n" . $this->boundary;
               $boundaryLen = strlen($boundary);
               $dataLength = strlen($data);

               // @ Search for boundary in data
               $pos = strpos($data, $boundary);

               if ($pos !== false) {
                  $afterBoundary = $pos + $boundaryLen;
                  $delimiter = self::classify(
                     $data,
                     $afterBoundary,
                     $dataLength,
                     $bodyComplete
                  );

                  // ! Do not write or close on a candidate until its complete
                  //   suffix proves that this is a delimiter.
                  if ($delimiter === self::DELIMITER_INVALID) {
                     $reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                     $data = '';
                     break;
                  }
                  if ($delimiter === self::DELIMITER_INCOMPLETE) {
                     // @ The prefix is certainly file data. Stream it now,
                     //   retain only the candidate, and keep the handle open.
                     if ($pos > 0) {
                        $this->write(substr($data, 0, $pos));
                     }
                     $this->tailBuffer = self::retain(
                        $data,
                        $pos,
                        $afterBoundary,
                        $dataLength
                     );
                     $data = null;
                     break;
                  }

                  // @ Valid boundary: only now write the final file bytes and
                  //   transfer the current part out of its open state.
                  if ($pos > 0) {
                     $this->write(substr($data, 0, $pos));
                  }
                  $this->close();

                  if ($delimiter === self::DELIMITER_CLOSED) {
                     $this->parsed = true;
                     $data = '';
                     break;
                  }

                  $this->state = self::STATE_PART_HEADER;
                  $this->headerBuffer = '';

                  $data = substr($data, $delimiter);
                  break;
               }

               // @ Boundary not found: write data except tail buffer to file
               // @ Keep enough bytes for boundary detection across chunks
               $safeLen = $dataLength - $boundaryLen - 2;

               if ($safeLen > 0) {
                  $safeData = substr($data, 0, $safeLen);

                  $this->write($safeData);

                  $this->tailBuffer = substr($data, $safeLen);
               }
               else {
                  // @ Not enough data to write safely, keep all in tail buffer
                  $this->tailBuffer = $data;
               }

               $data = null;
               break;

            case self::STATE_PART_BODY_FIELD:
               $boundary = "\r\n" . $this->boundary;
               $boundaryLen = strlen($boundary);
               $dataLength = strlen($data);

               // @ Search for boundary in data
               $pos = strpos($data, $boundary);

               if ($pos !== false) {
                  $afterBoundary = $pos + $boundaryLen;
                  $delimiter = self::classify(
                     $data,
                     $afterBoundary,
                     $dataLength,
                     $bodyComplete
                  );

                  // ! Classify before appending or committing this field. An
                  //   arbitrary suffix must never manufacture the next part.
                  if ($delimiter === self::DELIMITER_INVALID) {
                     $reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                     $data = '';
                     break;
                  }
                  if ($delimiter === self::DELIMITER_INCOMPLETE) {
                     // @ The prefix is certainly field data. Retain only the
                     //   candidate and remain in this field-body state.
                     if (
                        $pos > 0
                        && $appendField(substr($data, 0, $pos)) === false
                     ) {
                        $data = '';
                        break;
                     }
                     $this->tailBuffer = self::retain(
                        $data,
                        $pos,
                        $afterBoundary,
                        $dataLength
                     );
                     $data = null;
                     break;
                  }

                  // @ Boundary found: accumulate everything before boundary
                  if (
                     $pos > 0
                     && $appendField(substr($data, 0, $pos)) === false
                  ) {
                     $data = '';
                     break;
                  }

                  // @ Store field value
                  $fieldName = $this->currentFieldName;
                  if ($fieldName !== '') {
                     // @ Preserve parse_str() field-name shaping without
                     //   URL-encoding the potentially large value itself.
                     $index = count($this->fields);

                     // ? Price this completion BEFORE producing any of it —
                     //   `urlencode()` itself allocates, so its output is
                     //   bounded at 3x rather than measured. Covers the
                     //   encoded entry, the value's block, every name
                     //   component `parse_str()` will decode back, and the
                     //   nodes it will materialize.
                     $keys = self::weigh($fieldName);
                     $nodes = 1 + substr_count($fieldName, '[');
                     // ! `measure()` already owns the OLD aggregate. Two more
                     //   strings can coexist with it at the append peak: the
                     //   temporary encoded entry and the FULL new aggregate
                     //   destination. Zend may move a uniquely referenced
                     //   string while extending it, so subtracting the old
                     //   block assumes an in-place realloc that is not
                     //   guaranteed.
                     $held = strlen($this->fieldsEncoded);
                     $digits = strlen((string) $index);
                     $entry = self::expand($fieldName)
                        + 2
                        + $digits;
                     // ! php_url_encode() allocates its 3x worst case first,
                     //   then shrinks plain-name output. The final aggregate
                     //   uses `$entry`; its temporary must use the raw 3x cap.
                     $temporary = (3 * strlen($fieldName)) + 2 + $digits;
                     $growth = self::block($temporary)
                        + self::block($held + $entry)
                        + self::block(strlen($this->fieldBuffer))
                        + $keys
                        + ($nodes * self::NODE_OVERHEAD)
                        // ! Appending can replace the packed-array storage.
                        //   `measure()` owns the old slots; reserve the whole
                        //   destination while that storage is still live.
                        + (($index + 1) * self::ENTRY_OVERHEAD)
                        + ($index === 0 ? self::MAP_OVERHEAD : 0);

                     if ($this->Bodies->reserve($this->measure() + $growth) === false) {
                        $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
                        $data = '';
                        break;
                     }

                     $this->fields[] = $this->fieldBuffer;
                     $this->fieldsBlocks += self::block(strlen($this->fieldBuffer));
                     $this->fieldsEncoded .= urlencode($fieldName) . '=' . $index . '&';
                     $this->nodes += $nodes;
                     $this->keysBlocks += $keys;

                     // ! Settle the estimate to what is actually held now.
                     if ($this->charge() === false) {
                        $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
                        $data = '';
                        break;
                     }
                  }

                  $this->fieldBuffer = '';
                  $this->currentFieldSize = 0;

                  if ($delimiter === self::DELIMITER_CLOSED) {
                     $this->parsed = true;
                     $data = '';
                     break;
                  }

                  $this->state = self::STATE_PART_HEADER;
                  $this->headerBuffer = '';

                  $data = substr($data, $delimiter);
                  break;
               }

               // @ Boundary not found: accumulate data except tail buffer
               $safeLen = $dataLength - $boundaryLen - 2;

               if ($safeLen > 0) {
                  if ($appendField(substr($data, 0, $safeLen)) === false) {
                     $data = '';
                     break;
                  }
                  $this->tailBuffer = substr($data, $safeLen);
               }
               else {
                  $this->tailBuffer = $data;
               }

               $data = null;
               break;
         }

         if ($Package->rejected) {
            $Package->consumed = 0;
            return States::Rejected;
         }

         // @ If data is null, we need more data (save remainder in tail buffer)
         if ($data === null) {
            break;
         }
      }

      // @ Update Body metadata
      $Body->downloaded = $this->downloaded;

      if ($Package->rejected) {
         $Package->consumed = 0;
         return States::Rejected;
      }

      // @ Check if all data received
      if ($Body->length !== null && $this->downloaded >= $Body->length) {
         // ! A Content-Length-complete multipart body is malformed until its
         //   terminal boundary has been parsed. Never route a missing or
         //   truncated boundary as an empty successful request.
         if ($this->parsed === false) {
            $reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            $Package->Decoder = null;
            $Package->consumed = 0;
            return States::Rejected;
         }

         // ? Completion itself can exceed the worker budget while shaping the
         //   retained state, and it refuses before allocating when it does.
         if ($this->finish($Package) === false) {
            $Package->Decoder = null;
            $Package->consumed = 0;
            return States::Rejected;
         }

         $Body->waiting = false;
         $Body->streaming = true;
         $Package->Decoder = null;
         $Package->consumed = $consumed;
         // ! Restore the worker-global Request before the response cycle:
         //   `Encoder_::encode()` serializes `Server::$Request` (mirror of
         //   the L1-hit path in `Decoder_::decode()`).
         Server::$Request = $Request;
         return States::Complete;
      }

      // ? Single accounting point for everything this call left retained but
      //   did not append through `$appendField()` — the wire carried in
      //   `$tailBuffer` and the part header in `$headerBuffer`. Both survive
      //   until the next read, so an unfinished request cannot hold them for
      //   free. The transient overshoot is one transport read: the worker is a
      //   single-threaded loop, so only this connection is inside `decode()`.
      if ($this->charge() === false) {
         $reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
         $Package->Decoder = null;
         $Package->consumed = 0;
         return States::Rejected;
      }

      $Package->consumed = $consumed;
      return States::Incomplete;
   }

   /**
    * Release every temporary file still owned by this decoder.
    */
   private function abort (): void
   {
      if ($this->aborted || $this->finished) {
         return;
      }

      $this->aborted = true;

      if ($this->fileHandler !== null) {
         try {
            fclose($this->fileHandler);
         }
         catch (Throwable) {}

         $this->fileHandler = null;
      }

      try {
         // ! Cleanup is a no-throw boundary. One failed path must not strand
         //   every later tempfile or the unfinished-body reservation.
         $this->remove($this->pendingFile);

         foreach ($this->files as $file) {
            $this->remove((string) ($file['tmp_name'] ?? ''));
         }
      }
      finally {
         $this->files = [];
         $this->filesKeys = [];
         $this->tailBuffer = '';
         $this->headerBuffer = '';
         $this->fieldBuffer = '';
         $this->fields = [];
         $this->fieldsEncoded = '';
         $this->fieldsSize = 0;
         $this->fieldsBlocks = 0;
         $this->keysBlocks = 0;
         $this->filesSize = 0;
         $this->nodes = 0;
         $this->currentFieldName = '';
         $this->pendingFile = '';

         try {
            $this->Bodies->release();
         }
         catch (Throwable) {}
      }
   }

   /**
    * Stream file bytes with runtime safety checks.
    */
   private function write (string $chunk): void
   {
      if ($chunk === '' || $this->fileHandler === null) {
         return;
      }

      $fileIndex = count($this->files) - 1;
      if ($fileIndex < 0) {
         return;
      }

      /** @var array<string,bool|int|string> $file */
      $file = &$this->files[$fileIndex];

      if (($file['error'] ?? 0) !== 0) {
         return;
      }

      $chunkLength = strlen($chunk);

      // ! Enforce per-file cap during streaming.
      if ($this->currentFileSize + $chunkLength > Server\Request::$maxFileSize) {
         $file['error'] = UPLOAD_ERR_FORM_SIZE;
         $this->close();
         return;
      }

      // ! Enforce aggregate cross-worker disk cap. Reserves bytes against
      //   the shared counter; if the reservation would breach the global
      //   ceiling we abort this file with UPLOAD_ERR_CANT_WRITE without
      //   ever issuing the fwrite. Bytes already reserved for this file
      //   stay reserved until the temp file is unlinked.
      if (Downloads::reserve($chunkLength) === false) {
         $file['error'] = UPLOAD_ERR_CANT_WRITE;
         $this->close();
         return;
      }
      $tmpName = (string) ($file['tmp_name'] ?? '');
      Downloads::track($tmpName, $chunkLength);

      // ! Re-check free disk space periodically while streaming.
      $this->bytesSinceDiskCheck += $chunkLength;
      if ($this->bytesSinceDiskCheck >= 1048576) {
         try {
            $freeSpace = disk_free_space(BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/');
            if ($freeSpace !== false && $freeSpace < 1048576) {
               $file['error'] = UPLOAD_ERR_CANT_WRITE;
               $this->close();
               return;
            }
         } catch (Throwable) {
            $file['error'] = UPLOAD_ERR_CANT_WRITE;
            $this->close();
            return;
         }

         $this->bytesSinceDiskCheck = 0;
      }

      $written = fwrite($this->fileHandler, $chunk);
      if ($written === false) {
         // @ Reservation made but no bytes hit disk: roll back fully.
         Downloads::release($chunkLength);
         $tmpName = (string) ($file['tmp_name'] ?? '');
         Downloads::untrack($tmpName, $chunkLength);
         $file['error'] = UPLOAD_ERR_CANT_WRITE;
         $this->close();
         return;
      }

      if ($written > 0) {
         $this->currentFileSize += $written;
         $file['size'] = $this->currentFileSize;
      }

      if ($written !== $chunkLength) {
         // @ Partial write: release the unwritten portion so the counter
         //   tracks actual on-disk bytes.
         $unwritten = $chunkLength - $written;
         if ($unwritten > 0) {
            Downloads::release($unwritten);
            $tmpName = (string) ($file['tmp_name'] ?? '');
            Downloads::untrack($tmpName, $unwritten);
         }
         $file['error'] = UPLOAD_ERR_CANT_WRITE;
         $this->close();
      }
   }

   /**
    * Close the current file handler and update file size.
    */
   private function close (): void
   {
      $fileIndex = count($this->files) - 1;
      if ($fileIndex < 0) return;

      // @ Update file size and close handler
      $file = &$this->files[$fileIndex];

      if ($this->fileHandler !== null) {
         $size = $this->currentFileSize;
         $stat = fstat($this->fileHandler);
         if ($stat !== false && $stat['size'] > $size) {
            $size = (int) $stat['size'];
         }
         $file['size'] = $size;

         try {
            fclose($this->fileHandler);
         }
         catch (Throwable) {}

         $this->fileHandler = null;
      }
      else {
         $file['size'] = (int) ($file['size'] ?? 0);
         if (($file['error'] ?? 0) === 0) {
            $file['error'] = UPLOAD_ERR_NO_FILE;
         }
      }

      $this->currentFileSize = 0;
      $this->bytesSinceDiskCheck = 0;
   }

   /**
    * Finalize: populate $Request->fields and $Request->files, reset state.
    */
   /**
    * False means only one thing: the budget refused this completion and the
    * caller must reject. An already rejected/aborted/finished decoder has
    * nothing left to shape and keeps the caller's previous behavior.
    */
   private function finish (Packages $Package): bool
   {
      if ($Package->rejected || $this->aborted || $this->finished) {
         return true;
      }

      // ? Completion SHAPES the retained state into new buffers that COEXIST
      //   with the originals until this method hands everything over: the field
      //   map is re-parsed, and every file key is URL-encoded into an aggregate
      //   which `parse_str()` then decodes back into a third structure. So the
      //   bound has to cover all of them at once, priced BEFORE any is produced
      //   — allocating first and checking afterwards is the bypass this closes.
      //
      //   Per file: `urlencode()` can triple the key, `=` and `&` are two more
      //   bytes, the index is written once, and the decoded key reappears in
      //   the parsed map. The map itself is priced per NODE, never per part:
      //   multipart names keep bracket semantics, so one part named `a[b][c]`
      //   expands into a chain of nested arrays whose cost is dominated by the
      //   hashtables, not by the name. The reservation is deliberately NOT
      //   shrunk to the encoded size afterwards: that would hand back the very
      //   allowance the decoded maps are about to consume. Everything is
      //   released together at the hand-off below.
      //   Three terms per query, and they are NOT three copies of the same
      //   thing: `measure()` already holds the retained encoded query; the
      //   first term below is the MUTABLE COPY `parse_str()` consumes; the
      //   second bounds the DECODED keys and values it emits, using the
      //   encoded length only because decoding never expands. On top of that
      //   sits a fixed margin per produced map — the root hashtable, the
      //   parser's scratch and the allocator's rounding scale with nothing,
      //   so per-byte terms alone go short exactly on small inputs.
      $projected = $this->measure()
         + self::block(strlen($this->fieldsEncoded))
         + $this->keysBlocks
         + ($this->nodes * (self::NODE_OVERHEAD + self::ENTRY_OVERHEAD))
         + ($this->fieldsEncoded === '' ? 0 : self::MAP_OVERHEAD)
         + ($this->files === [] ? 0 : self::MAP_OVERHEAD)
         // ! Ownership validation below creates a second integer-keyed map
         //   with one entry per file. It coexists with the parsed file map and
         //   retained decoder records until the hand-off succeeds.
         + ($this->files === []
            ? 0
            : self::MAP_OVERHEAD + (count($this->files) * self::ENTRY_OVERHEAD));

      // @@ The file map is built here, so its aggregate is projected rather
      //    than measured — two blocks for the encoded string and its working
      //    copy, one per key the parser decodes back. urlencode() first
      //    allocates a 3x raw-name buffer even when its final output is shorter,
      //    so the largest per-entry temporary is separate from the aggregate.
      $aggregate = 0;
      $temporary = 0;
      foreach ($this->files as $index => $ignored) {
         $raw = $this->filesKeys[$index] ?? '';
         $key = strlen($raw);
         $digits = strlen((string) $index);

         // ! The decoded key is already in `$keysBlocks`, charged when the
         //   part was accepted — counting it again here is pure double count.
         $aggregate += self::expand($raw)
            + 2
            + $digits;
         $candidate = (3 * $key) + 2 + $digits;
         if ($candidate > $temporary) {
            $temporary = $candidate;
         }
      }
      if ($this->files === []) {
         // @ Preserve the empty-map projection used by field-only requests.
         $projected += 2 * self::block(0);
      }
      else {
         $projected += (2 * self::block($aggregate))
            + self::block($temporary);
      }

      if ($this->Bodies->reserve($projected) === false) {
         $this->Request->Body->waiting = false;
         $this->abort();

         if ($Package instanceof TCP_Packages) {
            $Package->reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
         }
         else {
            $Package->rejected = true;
         }

         return false;
      }

      // @ Close any open file handler
      if ($this->fileHandler !== null) {
         try {
            fclose($this->fileHandler);
         }
         catch (Throwable) {
            // ignore
         }

         $this->fileHandler = null;
      }

      // ! Fields/files must populate the OWNING Request — never the
      //   worker-global cell, which another connection may hold.
      $Request = $this->Request;

      // ! Build both maps in locals while the decoder retains ownership of
      //   every tempfile. Only a completely validated hand-off may clear
      //   `$files`: parse failures and key collisions must abort instead.
      /** @var array<string,array<string>|bool|float|int|string> $fieldsParsed */
      $fieldsParsed = [];
      /** @var array<string,array<string,bool|int|string|array<int|string,bool|int|string>>> $filesParsed */
      $filesParsed = [];

      try {
         if ($this->fieldsEncoded !== '') {
            $fieldsData = $this->fields;

            parse_str($this->fieldsEncoded, $fieldsParsed);
            array_walk_recursive($fieldsParsed, function (&$value) use ($fieldsData) {
               if (is_numeric($value) && isset($fieldsData[(int) $value])) {
                  $value = $fieldsData[(int) $value];
               }
            });

         }

         if (! empty($this->files)) {
            $filesEncoded = '';

            // @@
            foreach ($this->files as $index => $file) {
               $uploadKey = $this->filesKeys[$index] ?? '';
               $filesEncoded .= urlencode($uploadKey) . '=' . $index . '&';
            }

            if ($filesEncoded !== '') { // @phpstan-ignore notIdentical.alwaysTrue
               $filesData = $this->files;
               /** @var array<int,true> $seen */
               $seen = [];
               $valid = true;

               parse_str($filesEncoded, $filesParsed);
               array_walk_recursive(
                  $filesParsed,
                  function (&$value) use ($filesData, &$seen, &$valid): void {
                     if (
                        ! is_string($value)
                        || $value === ''
                        || strspn($value, '0123456789') !== strlen($value)
                     ) {
                        $valid = false;
                        return;
                     }

                     $index = (int) $value;
                     if (
                        (string) $index !== $value
                        || ! isset($filesData[$index])
                        || isset($seen[$index])
                     ) {
                        $valid = false;
                        return;
                     }

                     $seen[$index] = true;
                     $value = $filesData[$index];
                  }
               );

               // ! parse_str() silently overwrites normalized duplicates and
               //   parent/child collisions. Requiring an exact index bijection
               //   prevents any created tempfile from disappearing outside
               //   both decoder and Request cleanup ownership.
               if (! $valid || count($seen) !== count($filesData)) {
                  $Request->Body->waiting = false;
                  $this->abort();

                  if ($Package instanceof TCP_Packages) {
                     $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  }
                  else {
                     $Package->rejected = true;
                  }

                  return false;
               }
            }
         }

         // @ Transfer both maps together. Until the files setter succeeds,
         //   abort() remains the sole owner of every exact temporary path.
         $Request->fields = $fieldsParsed; // @phpstan-ignore assign.propertyType
         $Request->files = $filesParsed; // @phpstan-ignore assign.propertyType

         // @ Transfer ownership to Request. Once these arrays are cleared,
         //   disconnect()/destruction cannot delete completed uploads that the
         //   handler still needs; Request::clean() owns their final reclamation.
      }
      catch (Throwable) {
         $Request->Body->waiting = false;
         $this->abort();

         if (! $Package->rejected) {
            if ($Package instanceof TCP_Packages) {
               $Package->reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
            }
            else {
               $Package->rejected = true;
            }
         }

         return false;
      }

      $this->fields = [];
      $this->fieldsEncoded = '';
      $this->fieldsSize = 0;
      $this->fieldsBlocks = 0;
      $this->keysBlocks = 0;
      $this->filesSize = 0;
      $this->nodes = 0;
      $this->currentFieldName = '';
      $this->pendingFile = '';
      // ! The values now belong to the Request, dispatched and freed with it,
      //   so they leave the unfinished-body budget here — `abort()` returns
      //   early once `$finished` is set and would never release them.
      $this->Bodies->release();
      $this->files = [];
      $this->filesKeys = [];
      $this->finished = true;

      // :
      return true;
   }
}

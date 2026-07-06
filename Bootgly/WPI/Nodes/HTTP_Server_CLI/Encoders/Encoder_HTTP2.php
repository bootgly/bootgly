<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use function explode;
use function fclose;
use function fopen;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * HTTP/2 response wire serializer (RFC 9113 §8.2).
 *
 * Not a dispatch encoder: routing and middleware stay on the canonical
 * `Encoder_`/`Encoder_Testing` pipeline. `Response\Raw::encode()` branches
 * here when the bound Request carries a stream id, so normal, testing and
 * deferred (Fiber) responses all serialize identically.
 *
 * Emits pending control frames + HEADERS(+CONTINUATION)(+DATA) as one
 * buffer — typically a single `fwrite` per response. Header blocks are
 * context-free (`HPACK::encode()` never touches the dynamic table), so the
 * previous block is replayed byte-identically while status/headers/length
 * are unchanged.
 */
final class Encoder_HTTP2
{
   // * Metadata
   // # Context-free header-block cache (single slot, per worker)
   private static int $cachedCode = 0;
   private static string $cachedHeader = '';
   private static null|int $cachedSize = null;
   private static string $cachedBlock = '';


   /**
    * Serialize a routed Response into HTTP/2 frames for `$Request->stream`.
    *
    * @param array<int, array<string, mixed>> $files Upload queue diverted from `$Package->uploading`.
    * @param int<0, max>|null $length
    * @param-out int<0, max> $length
    */
   public static function frame (
      Response $Response,
      int $code,
      Request $Request,
      array $files,
      Packages $Package,
      null|int &$length
   ): string
   {
      // !
      $H2 = $Package->decoded;
      if ($H2 instanceof Decoder_HTTP2 === false) {
         $length = 0;
         return '';
      }

      $stream = $Request->stream;
      $Stream = $H2->Streams[$stream] ?? null;

      // @ Pending control frames always ride along (single fwrite)
      $outbox = $H2->outbox;
      $H2->outbox = '';

      // ? Stream already reset — the response has no destination
      if ($Stream === null) {
         $length = strlen($outbox);
         return $outbox;
      }

      // ! Body: buffered raw + queued upload segments. HTTP/2 must frame file
      //   responses itself; the HTTP/1.1 `uploading[]` writer bypasses h2 DATA.
      $body = $Response->Body->raw;
      $chunks = [];
      $queued = 0;
      if ($files !== []) {
         $queue = self::queue($files);
         if ($queue === null) {
            $code = 500;
            $body = '';
         }
         else {
            [$chunks, $queued] = $queue;
         }
      }

      $size = strlen($body) + $queued;

      // ? HEAD: full header block (with content-length), no DATA
      if ($Request->method === 'HEAD') {
         $body = '';
         $chunks = [];
      }

      // @ Header block — cache hit when status/header block/length repeat
      $Header = $Response->Header;
      $Header->build();
      if (
         self::$cachedBlock !== ''
         && self::$cachedCode === $code
         && self::$cachedHeader === $Header->raw
         && self::$cachedSize === $size
      ) {
         $block = self::$cachedBlock;
      }
      else {
         $block = self::compress($code, $Header->raw, $size);

         self::$cachedCode = $code;
         self::$cachedHeader = $Header->raw;
         self::$cachedSize = $size;
         self::$cachedBlock = $block;
      }

      // @ HEADERS (+CONTINUATION when the block exceeds the peer frame size)
      $limit = $H2->Remote->frame;
      $closing = ($body === '' && $chunks === []) ? HTTP2::FLAG_END_STREAM : 0;
      $frames = '';

      if (strlen($block) <= $limit) {
         $frames .= Frame::pack(
            HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | $closing, $stream, $block
         );
      }
      else {
         $frames .= Frame::pack(
            HTTP2::FRAME_HEADERS, $closing, $stream, substr($block, 0, $limit)
         );
         $offset = $limit;
         $total = strlen($block);
         while ($offset < $total) {
            $chunk = substr($block, $offset, $limit);
            $offset += $limit;
            $frames .= Frame::pack(
               HTTP2::FRAME_CONTINUATION,
               $offset >= $total ? HTTP2::FLAG_END_HEADERS : 0,
               $stream,
               $chunk
            );
         }
      }

      // @ DATA — bounded by the connection + stream send windows.
      if ($body !== '' || $chunks !== []) {
         $Stream->backlog = $body;
         $Stream->chunks = $chunks;
         $Stream->chunk = 0;

         [$data, $done] = $H2->drain($Stream, $stream);
         $frames .= $data;

         if ($done === false) {
            $Stream->responded = true;

            $raw = "{$outbox}{$frames}";
            $length = strlen($raw);
            return $raw;
         }
      }

      // @ Fully responded — release the stream
      $Stream->close();
      unset($H2->Streams[$stream]);
      $H2->opened--;

      // :
      $raw = "{$outbox}{$frames}";
      $length = strlen($raw);
      return $raw;
   }

   /**
    * Build a context-free HPACK block from the built header string.
    *
    * `:status` leads; names are lowercased; connection-specific fields are
    * stripped (RFC 9113 §8.2.2); `content-length` is appended from the body.
    */
   public static function compress (int $code, string $header, null|int $size): string
   {
      // !
      $fields = [
         [':status', (string) $code]
      ];

      // @@ One "Name: value" line per field (Set-Cookie repeats naturally)
      foreach (explode("\r\n", $header) as $line) {
         if ($line === '') {
            continue;
         }

         $mark = strpos($line, ':');
         if ($mark === false || $mark === 0) {
            continue;
         }

         $name = strtolower(substr($line, 0, $mark));
         // ? Connection-specific fields do not exist in HTTP/2
         if (
            $name === 'connection'
            || $name === 'keep-alive'
            || $name === 'proxy-connection'
            || $name === 'transfer-encoding'
            || $name === 'upgrade'
         ) {
            continue;
         }

         $fields[] = [$name, ltrim(substr($line, $mark + 1), " \t")];
      }

      if ($size !== null) {
         $fields[] = ['content-length', (string) $size];
      }

      // :
      return HPACK::encode($fields);
   }

   /**
    * Normalize queued upload file parts into streamable DATA segments.
    *
    * @param array<int, array<string, mixed>> $files
    *
    * @return null|array{0: array<int, array<string, mixed>>, 1: int}
    */
   protected static function queue (array $files): null|array
   {
      $chunks = [];
      $size = 0;

      // @@
      foreach ($files as $queued) {
         $file = $queued['file'] ?? null;
         if (is_string($file) === false || $file === '') {
            return null;
         }

         $Handler = @fopen($file, 'r');
         if ($Handler === false) {
            return null;
         }
         @fclose($Handler);

         $parts = is_array($queued['parts'] ?? null) ? $queued['parts'] : [];
         $pads = is_array($queued['pads'] ?? null) ? $queued['pads'] : [];

         foreach ($parts as $index => $part) {
            if (is_array($part) === false) {
               continue;
            }
            $offset = $part['offset'] ?? null;
            $bytes = $part['length'] ?? null;
            if (is_int($offset) === false || is_int($bytes) === false || $bytes < 1) {
               continue;
            }

            $pad = $pads[$index] ?? null;
            if (is_array($pad)) {
               $prepend = $pad['prepend'] ?? '';
               if (is_string($prepend) && $prepend !== '') {
                  $chunks[] = ['data' => $prepend, 'position' => 0];
                  $size += strlen($prepend);
               }
            }

            $chunks[] = [
               'file' => $file,
               'offset' => $offset,
               'length' => $bytes,
               'position' => 0
            ];
            $size += $bytes;

            if (is_array($pad)) {
               $append = $pad['append'] ?? '';
               if (is_string($append) && $append !== '') {
                  $chunks[] = ['data' => $append, 'position' => 0];
                  $size += strlen($append);
               }
            }
         }
      }

      // :
      return [$chunks, $size];
   }
}

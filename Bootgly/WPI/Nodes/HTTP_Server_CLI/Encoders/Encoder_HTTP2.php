<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use function explode;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function min;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use Throwable;

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
   // @ Materialized upload ceiling — file parts above this respond 500
   //   (window-respecting file streaming is follow-up work).
   protected const int UPLOAD = 16 * 1024 * 1024;

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

      // ! Body: buffered raw + materialized upload parts (h2 has no raw
      //   file pump — the HTTP/1.1 `uploading[]` writer bypasses framing)
      $body = $Response->Body->raw;
      if ($files !== []) {
         $parts = self::load($files);
         if ($parts === null) {
            $code = 500;
            $body = '';
         }
         else {
            $body .= $parts;
         }
      }

      $size = strlen($body);

      // ? HEAD: full header block (with content-length), no DATA
      if ($Request->method === 'HEAD') {
         $body = '';
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
      $closing = ($body === '') ? HTTP2::FLAG_END_STREAM : 0;
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

      // @ DATA — bounded by the connection + stream send windows
      if ($body !== '') {
         $window = min($H2->window, $Stream->window);
         $send = ($window > 0) ? min($window, strlen($body)) : 0;

         if ($send > 0) {
            $H2->window -= $send;
            $Stream->window -= $send;

            $done = ($send === strlen($body));
            for ($offset = 0; $offset < $send; $offset += $limit) {
               $chunk = substr($body, $offset, min($limit, $send - $offset));
               $frames .= Frame::pack(
                  HTTP2::FRAME_DATA,
                  ($done && $offset + $limit >= $send) ? HTTP2::FLAG_END_STREAM : 0,
                  $stream,
                  $chunk
               );
            }
         }

         // ?! Window exhausted — park the tail; `Decoder_HTTP2::pump()`
         //   drains it when WINDOW_UPDATE / SETTINGS credit arrives.
         if ($send < strlen($body)) {
            $Stream->backlog = substr($body, $send);
            $Stream->responded = true;

            $raw = "{$outbox}{$frames}";
            $length = strlen($raw);
            return $raw;
         }
      }

      // @ Fully responded — release the stream
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
    * Materialize queued upload file parts (offset/length + pads) into a
    * DATA payload, bounded by the `UPLOAD` ceiling.
    *
    * @param array<int, array<string, mixed>> $files
    *
    * @return null|string `null` when a part is unreadable or the ceiling is hit.
    */
   protected static function load (array $files): null|string
   {
      // !
      $payload = '';

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
               $payload .= is_string($prepend) ? $prepend : '';
            }

            // ? Ceiling guard before the read
            if (strlen($payload) + $bytes > self::UPLOAD) {
               @fclose($Handler);
               return null;
            }

            try {
               @fseek($Handler, $offset);
               $data = @fread($Handler, $bytes);
            }
            catch (Throwable) {
               $data = false;
            }
            if ($data === false || strlen($data) !== $bytes) {
               @fclose($Handler);
               return null;
            }
            $payload .= $data;

            if (is_array($pad)) {
               $append = $pad['append'] ?? '';
               $payload .= is_string($append) ? $append : '';
            }
         }

         @fclose($Handler);
      }

      // :
      return $payload;
   }
}

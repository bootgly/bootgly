<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar;


use function chr;
use function dechex;
use function mt_rand;
use function str_repeat;
use function strlen;
use function substr;


/**
 * Body — generators for HTTP message bodies.
 */
class Body
{
   /**
    * Identity body of `$size` bytes, paired with its `Content-Length` value.
    *
    * @return array{0: string, 1: int} [body, contentLength]
    */
   public static function fill (int $size): array
   {
      $body = $size > 0 ? str_repeat('A', $size) : '';
      return [$body, $size];
   }

   /**
    * Chunked body containing `$size` total payload bytes split across
    * randomized chunk sizes (each chunk in [chunkMin, chunkMax]).
    *
    * Always terminates with `0\r\n\r\n`.
    */
   public static function chunk (int $size, int $chunkMin = 1, int $chunkMax = 1024): string
   {
      $payload = $size > 0 ? str_repeat('B', $size) : '';
      $out = '';
      $offset = 0;
      $remaining = strlen($payload);
      while ($remaining > 0) {
         $take = mt_rand($chunkMin, $chunkMax);
         if ($take > $remaining) $take = $remaining;
         $chunk = substr($payload, $offset, $take);
         $out .= dechex($take) . "\r\n" . $chunk . "\r\n";
         $offset += $take;
         $remaining -= $take;
      }
      $out .= "0\r\n\r\n";
      return $out;
   }

   /**
    * Multipart/form-data body with `$fields` text fields and `$files` file
    * parts. Returns [body, boundary].
    *
    * @param int $fieldSize bytes per text field
    * @param int $fileSize  bytes per file content
    * @return array{0: string, 1: string} [body, boundary]
    */
   public static function form (
      int $fields = 1,
      int $files = 0,
      int $fieldSize = 16,
      int $fileSize = 64,
   ): array
   {
      $boundary = '----BootglyFuzz';
      for ($n = 0; $n < 8; $n++) {
         $byte = dechex(mt_rand(0, 255));
         $boundary .= strlen($byte) === 1 ? '0' . $byte : $byte;
      }

      $body = '';
      for ($i = 0; $i < $fields; $i++) {
         $body .= "--{$boundary}\r\n";
         $body .= "Content-Disposition: form-data; name=\"f{$i}\"\r\n\r\n";
         $body .= str_repeat('x', $fieldSize) . "\r\n";
      }
      for ($j = 0; $j < $files; $j++) {
         $body .= "--{$boundary}\r\n";
         $body .= "Content-Disposition: form-data; name=\"file{$j}\"; filename=\"f{$j}.bin\"\r\n";
         $body .= "Content-Type: application/octet-stream\r\n\r\n";
         $body .= str_repeat(chr(0x41 + ($j % 26)), $fileSize) . "\r\n";
      }
      $body .= "--{$boundary}--\r\n";
      return [$body, $boundary];
   }
}

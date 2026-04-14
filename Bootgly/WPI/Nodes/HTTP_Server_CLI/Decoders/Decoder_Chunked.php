<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function hexdec;
use function strlen;
use function strpos;
use function substr;
use function time;
use function trim;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Chunked extends Decoders
{
   // * Config
   private const int MAX_BODY_SIZE = 10485760; // 10 MB

   // * Data
   private static string $buffer;
   private static string $body;

   // * Metadata
   private static int $decoded;
   private static int $state;
   private static int $chunkSize;
   private static int $chunkRead;
   private static int $totalSize;

   // # States
   private const int READ_SIZE = 0;
   private const int READ_DATA = 1;


   public static function init (): void
   {
      self::$buffer = '';
      self::$body = '';
      self::$decoded = time();
      self::$state = self::READ_SIZE;
      self::$chunkSize = 0;
      self::$chunkRead = 0;
      self::$totalSize = 0;
   }

   public static function feedInitial (string $data): void
   {
      self::$buffer .= $data;
   }

   public static function decode (Packages $Package, string $buffer, int $size): int
   {
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;
      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Body = $Request->Body;

      if (! $Body->waiting) {
         $Server::$Decoder = new Decoder_;
         return Decoder_::decode($Package, $buffer, $size);
      }

      // * Metadata
      $elapsed = time() - self::$decoded;
      if ($elapsed >= 60) {
         $Body->waiting = false;
         $Server::$Decoder = new Decoder_;
         return Decoder_::decode($Package, $buffer, $size);
      }

      // @ Append incoming data
      self::$buffer .= $buffer;

      // @ Process chunks
      while (true) {
         switch (self::$state) {
            case self::READ_SIZE:
               // @ Find the chunk size line (\r\n terminated)
               $pos = strpos(self::$buffer, "\r\n");
               if ($pos === false) {
                  return 0; // Need more data
               }

               $sizeLine = substr(self::$buffer, 0, $pos);
               self::$buffer = substr(self::$buffer, $pos + 2);

               // @ Strip chunk extensions (RFC 9112 §7.1.1)
               $semiPos = strpos($sizeLine, ';');
               if ($semiPos !== false) {
                  $sizeLine = substr($sizeLine, 0, $semiPos);
               }

               $chunkSize = (int) hexdec(trim($sizeLine));

               if ($chunkSize === 0) {
                  // @ Last chunk: body complete
                  $Body->raw = self::$body;
                  $Body->length = self::$totalSize;
                  $Body->downloaded = self::$totalSize;
                  $Body->waiting = false;

                  // @ Clean up
                  self::$body = '';
                  self::$buffer = '';

                  $Server::$Decoder = new Decoder_;

                  return self::$totalSize;
               }

               // @ Validate total size
               if (self::$totalSize + $chunkSize > self::MAX_BODY_SIZE) {
                  $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $Body->waiting = false;
                  $Server::$Decoder = new Decoder_;
                  return 0;
               }

               self::$chunkSize = $chunkSize;
               self::$chunkRead = 0;
               self::$state = self::READ_DATA;
               break;

            case self::READ_DATA:
               $remaining = self::$chunkSize - self::$chunkRead;
               $available = strlen(self::$buffer);

               if ($available === 0) {
                  return 0; // Need more data
               }

               $toRead = ($available < $remaining) ? $available : $remaining;
               self::$body .= substr(self::$buffer, 0, $toRead);
               self::$buffer = substr(self::$buffer, $toRead);
               self::$chunkRead += $toRead;
               self::$totalSize += $toRead;

               if (self::$chunkRead < self::$chunkSize) {
                  return 0; // Need more data for this chunk
               }

               // @ Consume trailing \r\n after chunk data
               if (strlen(self::$buffer) < 2) {
                  return 0; // Need the trailing CRLF
               }
               self::$buffer = substr(self::$buffer, 2);

               self::$state = self::READ_SIZE;
               break;
         }
      }
   }
}

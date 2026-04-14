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


use const BOOTGLY_WORKING_BASE;
use function basename;
use function count;
use function time;
use function strlen;
use function strpos;
use function substr;
use function strtolower;
use function trim;
use function explode;
use function preg_match;
use function preg_replace;
use function urlencode;
use function parse_str;
use function array_walk_recursive;
use function tempnam;
use function fopen;
use function fwrite;
use function fclose;
use function disk_free_space;
use function is_dir;
use function mkdir;
use Throwable;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Downloading extends Decoders
{
   // * Config
   private static string $boundary;

   // * Data
   private static string $tailBuffer;
   private static string $headerBuffer;
   private static string $fieldBuffer;
   private static string $postEncoded;
   /** @var array<int,array<string,bool|int|string>> */
   private static array $files;
   /** @var array<int,string> */
   private static array $filesKeys;
   private static string $currentFieldName;
   /** @var resource|null */
   private static $fileHandler;

   // * Metadata
   private static int $decoded;
   private static int $read;
   private static int $state;
   private static int $downloaded;

   // # States
   private const int STATE_BOUNDARY_START  = 0;
   private const int STATE_PART_HEADER     = 1;
   private const int STATE_PART_BODY_FILE  = 2;
   private const int STATE_PART_BODY_FIELD = 3;


   public static function init (string $boundary): void
   {
      // * Config
      self::$boundary = $boundary;

      // * Data
      self::$tailBuffer = '';
      self::$headerBuffer = '';
      self::$fieldBuffer = '';
      self::$postEncoded = '';
      self::$files = [];
      self::$filesKeys = [];
      self::$currentFieldName = '';
      self::$fileHandler = null;

      // * Metadata
      self::$decoded = time();
      self::$read = 0;
      self::$state = self::STATE_BOUNDARY_START;
      self::$downloaded = 0;
   }

   /**
    * Feed initial body data from the first buffer into the streaming decoder.
    */
   public static function feedInitial (string $data): void
   {
      self::$tailBuffer = $data;
   }

   public static function decode (Packages $Package, string $buffer, int $size): int
   {
      // !
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;
      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Body = $Request->Body;

      // @ Check if Request Body is waiting data
      if (! $Body->waiting) {
         $Server::$Decoder = new Decoder_;
         return Decoder_::decode($Package, $buffer, $size);
      }

      // ? Valid HTTP Client Body Timeout
      $elapsed = time() - self::$decoded;
      if ($elapsed >= 60 && self::$read === self::$downloaded) {
         self::finish();
         $Server::$Decoder = new Decoder_;
         return Decoder_::decode($Package, $buffer, $size);
      }

      // @ Accumulate downloaded bytes
      self::$downloaded += $size;
      self::$read = self::$downloaded;

      // @ Prepend tail buffer from previous chunk
      $data = self::$tailBuffer . $buffer;
      self::$tailBuffer = '';

      // @ Process data through state machine
      while ($data !== '') {
         switch (self::$state) {
            case self::STATE_BOUNDARY_START:
               $data = self::parseBoundaryStart($data);
               break;

            case self::STATE_PART_HEADER:
               $data = self::parsePartHeader($data);
               break;

            case self::STATE_PART_BODY_FILE:
               $data = self::parsePartBodyFile($data);
               break;

            case self::STATE_PART_BODY_FIELD:
               $data = self::parsePartBodyField($data);
               break;
         }

         // @ If data is null, we need more data (save remainder in tail buffer)
         if ($data === null) {
            break;
         }
      }

      // @ Update Body metadata
      $Body->downloaded = self::$downloaded;

      // @ Check if all data received
      if ($Body->length !== null && self::$downloaded >= $Body->length) {
         self::finish();
         return $Body->length;
      }

      return 0;
   }

   private static function parseBoundaryStart (string $data): ?string
   {
      $boundary = self::$boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);
      if ($pos === false) {
         // @ Not enough data, save as tail buffer
         self::$tailBuffer = $data;
         return null;
      }

      // @ Check if this is the final boundary (--boundary--)
      $afterBoundary = $pos + $boundaryLen;
      if ($afterBoundary + 2 <= strlen($data)) {
         if (substr($data, $afterBoundary, 2) === '--') {
            // @ Final boundary: finish
            self::finish();
            return '';
         }
      }

      // @ Skip boundary + \r\n
      $nextPos = $afterBoundary + 2; // +2 for \r\n
      if ($nextPos > strlen($data)) {
         self::$tailBuffer = $data;
         return null;
      }

      self::$state = self::STATE_PART_HEADER;
      self::$headerBuffer = '';

      return substr($data, $nextPos);
   }

   private static function parsePartHeader (string $data): ?string
   {
      self::$headerBuffer .= $data;

      // @ Search for end of headers (\r\n\r\n)
      $headerEnd = strpos(self::$headerBuffer, "\r\n\r\n");
      if ($headerEnd === false) {
         // @ Need more data
         return null;
      }

      // @ Extract headers
      $headersRaw = substr(self::$headerBuffer, 0, $headerEnd);
      $remainder = substr(self::$headerBuffer, $headerEnd + 4);
      self::$headerBuffer = '';

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
                  // ! Sanitize filename: strip directory traversal and restrict to safe characters
                  $rawFilename = basename($match[2]);
                  $safeFilename = (string) preg_replace('/[^\w.\- ]/', '_', $rawFilename);
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
               $file['type'] = trim($value);
               break;
         }
      }

      if ($isFile && $uploadKey !== false) {
         // @ Open temp file for streaming writes
         $tempUploadedDir = BOOTGLY_WORKING_BASE . '/workdata/temp/files/downloaded/';

         if (! is_dir($tempUploadedDir)) {
            mkdir($tempUploadedDir, 0700, true);
         }

         // @ Check disk space
         try {
            $freeSpace = disk_free_space($tempUploadedDir);
            if ($freeSpace !== false && $freeSpace < 1048576) { // @ Minimum 1MB free
               $file['error'] = UPLOAD_ERR_CANT_WRITE;
            }
         }
         catch (Throwable) {
            $file['error'] = UPLOAD_ERR_CANT_WRITE;
         }

         $tempFile = tempnam($tempUploadedDir, '');
         if ($tempFile === false) {
            $file['error'] = UPLOAD_ERR_CANT_WRITE;
         }
         else {
            $file['tmp_name'] = $tempFile;

            try {
               $handle = fopen($tempFile, 'w+');
               if ($handle === false) {
                  self::$fileHandler = null;
                  $file['error'] = UPLOAD_ERR_CANT_WRITE;
               }
               else {
                  self::$fileHandler = $handle;
               }
            }
            catch (Throwable) {
               self::$fileHandler = null;
               $file['error'] = UPLOAD_ERR_CANT_WRITE;
            }
         }

         self::$filesKeys[] = $uploadKey;
         self::$files[] = $file; // @ Index will be updated with size at close

         self::$state = self::STATE_PART_BODY_FILE;
      }
      else if ($fieldName !== '') {
         self::$currentFieldName = $fieldName;
         self::$fieldBuffer = '';
         self::$state = self::STATE_PART_BODY_FIELD;
      }

      return $remainder;
   }

   private static function parsePartBodyFile (string $data): ?string
   {
      $boundary = "\r\n" . self::$boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);

      if ($pos !== false) {
         // @ Boundary found: write everything before boundary to file
         $fileData = substr($data, 0, $pos);

         if (self::$fileHandler !== null && $fileData !== '') {
            fwrite(self::$fileHandler, $fileData);
         }

         // @ Close file and finalize file entry
         self::closeCurrentFile();

         // @ Move past boundary
         $afterBoundary = $pos + $boundaryLen;

         // @ Check if this is the final boundary (--)
         if ($afterBoundary + 2 <= strlen($data)) {
            if (substr($data, $afterBoundary, 2) === '--') {
               self::finish();
               return '';
            }
         }

         // @ Skip \r\n after boundary
         $nextPos = $afterBoundary + 2; // +2 for \r\n
         if ($nextPos > strlen($data)) {
            self::$tailBuffer = substr($data, $afterBoundary);
            self::$state = self::STATE_BOUNDARY_START;
            return null;
         }

         self::$state = self::STATE_PART_HEADER;
         self::$headerBuffer = '';

         return substr($data, $nextPos);
      }

      // @ Boundary not found: write data except tail buffer to file
      // @ Keep enough bytes for boundary detection across chunks
      $safeLen = strlen($data) - $boundaryLen - 2;

      if ($safeLen > 0) {
         $safeData = substr($data, 0, $safeLen);

         if (self::$fileHandler !== null) {
            fwrite(self::$fileHandler, $safeData);
         }

         self::$tailBuffer = substr($data, $safeLen);
      }
      else {
         // @ Not enough data to write safely, keep all in tail buffer
         self::$tailBuffer = $data;
      }

      return null;
   }

   private static function parsePartBodyField (string $data): ?string
   {
      $boundary = "\r\n" . self::$boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);

      if ($pos !== false) {
         // @ Boundary found: accumulate everything before boundary
         self::$fieldBuffer .= substr($data, 0, $pos);

         // @ Store field value
         $fieldName = self::$currentFieldName;
         if ($fieldName !== '') {
            self::$postEncoded .= urlencode($fieldName) . '=' . urlencode(self::$fieldBuffer) . '&';
         }

         self::$fieldBuffer = '';

         // @ Move past boundary
         $afterBoundary = $pos + $boundaryLen;

         // @ Check if this is the final boundary (--)
         if ($afterBoundary + 2 <= strlen($data)) {
            if (substr($data, $afterBoundary, 2) === '--') {
               self::finish();
               return '';
            }
         }

         // @ Skip \r\n after boundary
         $nextPos = $afterBoundary + 2;
         if ($nextPos > strlen($data)) {
            self::$tailBuffer = substr($data, $afterBoundary);
            self::$state = self::STATE_BOUNDARY_START;
            return null;
         }

         self::$state = self::STATE_PART_HEADER;
         self::$headerBuffer = '';

         return substr($data, $nextPos);
      }

      // @ Boundary not found: accumulate data except tail buffer
      $safeLen = strlen($data) - $boundaryLen - 2;

      if ($safeLen > 0) {
         self::$fieldBuffer .= substr($data, 0, $safeLen);
         self::$tailBuffer = substr($data, $safeLen);
      }
      else {
         self::$tailBuffer = $data;
      }

      return null;
   }

   /**
    * Close the current file handler and update file size.
    */
   private static function closeCurrentFile (): void
   {
      $fileIndex = count(self::$files) - 1;
      if ($fileIndex < 0) return;

      // @ Update file size and close handler
      $file = &self::$files[$fileIndex];

      if (self::$fileHandler !== null) {
         // @ fstat returns size of data written so far (before the last fwrite in the caller)
         // The lastWriteSize was already written before this method is called,
         // so fstat after the write gives the total
         $stat = fstat(self::$fileHandler);
         $file['size'] = $stat !== false ? $stat['size'] : 0;

         try {
            fclose(self::$fileHandler);
         }
         catch (Throwable) {}

         self::$fileHandler = null;
      }
      else {
         $file['size'] = 0;
         $file['error'] = UPLOAD_ERR_NO_FILE;
      }
   }

   /**
    * Finalize: populate $_FILES and $_POST, reset state.
    */
   private static function finish (): void
   {
      // @ Close any open file handler
      if (self::$fileHandler !== null) {
         try {
            fclose(self::$fileHandler);
         }
         catch (Throwable) {}
         self::$fileHandler = null;
      }

      // @ Populate $_POST
      if (self::$postEncoded !== '') {
         parse_str(self::$postEncoded, $_POST);
      }

      // @ Populate $_FILES
      if (! empty(self::$files)) {
         $filesEncoded = '';

         foreach (self::$files as $index => $file) {
            $uploadKey = self::$filesKeys[$index] ?? '';
            $filesEncoded .= urlencode($uploadKey) . '=' . $index . '&';
         }

         if ($filesEncoded !== '') { // @phpstan-ignore notIdentical.alwaysTrue
            $filesData = self::$files;
            parse_str($filesEncoded, $_FILES);
            array_walk_recursive($_FILES, function (&$value) use ($filesData) {
               if (is_numeric($value) && isset($filesData[(int) $value])) {
                  $value = $filesData[(int) $value];
               }
            });
         }
      }

      // @ Update Body state
      $WPI = WPI;
      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Request->Body->waiting = false;
      $Request->Body->streaming = true;
   }
}

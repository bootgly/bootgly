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
use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_NO_FILE;
use function array_walk_recursive;
use function basename;
use function count;
use function disk_free_space;
use function explode;
use function fclose;
use function fopen;
use function fstat;
use function fwrite;
use function is_dir;
use function is_numeric;
use function mkdir;
use function parse_str;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function tempnam;
use function time;
use function trim;
use function urlencode;
use Throwable;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Downloading extends Decoders
{
   // * Config
   private string $boundary;

   // * Data
   private string $tailBuffer;
   private string $headerBuffer;
   private string $fieldBuffer;
   private string $postEncoded;
   /** @var array<int,array<string,bool|int|string>> */
   private array $files;
   /** @var array<int,string> */
   private array $filesKeys;
   private string $currentFieldName;
   /** @var resource|null */
   private $fileHandler = null;

   // * Metadata
   private int $decoded;
   private int $read;
   private int $state;
   private int $downloaded;

   // # States
   private const int STATE_BOUNDARY_START  = 0;
   private const int STATE_PART_HEADER     = 1;
   private const int STATE_PART_BODY_FILE  = 2;
   private const int STATE_PART_BODY_FIELD = 3;


   public function init (string $boundary): void
   {
      // * Config
      $this->boundary = $boundary;

      // * Data
      $this->tailBuffer = '';
      $this->headerBuffer = '';
      $this->fieldBuffer = '';
      $this->postEncoded = '';
      $this->files = [];
      $this->filesKeys = [];
      $this->currentFieldName = '';
      $this->fileHandler = null;

      // * Metadata
      $this->decoded = time();
      $this->read = 0;
      $this->state = self::STATE_BOUNDARY_START;
      $this->downloaded = 0;
   }

   /**
    * Feed initial body data from the first buffer into the streaming decoder.
    */
   public function feed (string $data): void
   {
      $this->tailBuffer = $data;
   }

   public function decode (Packages $Package, string $buffer, int $size): int
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
         $Package->Decoder = null;
         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // ? Valid HTTP Client Body Timeout
      $elapsed = time() - $this->decoded;
      if ($elapsed >= 60 && $this->read === $this->downloaded) {
         $this->finish();
         $Package->Decoder = null;
         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // @ Accumulate downloaded bytes
      $this->downloaded += $size;
      $this->read = $this->downloaded;

      // @ Prepend tail buffer from previous chunk
      $data = $this->tailBuffer . $buffer;
      $this->tailBuffer = '';

      // @ Process data through state machine
      while ($data !== '') {
         switch ($this->state) {
            case self::STATE_BOUNDARY_START:
               $data = $this->parseBoundaryStart($data);
               break;

            case self::STATE_PART_HEADER:
               $data = $this->parsePartHeader($data);
               break;

            case self::STATE_PART_BODY_FILE:
               $data = $this->parsePartBodyFile($data);
               break;

            case self::STATE_PART_BODY_FIELD:
               $data = $this->parsePartBodyField($data);
               break;
         }

         // @ If data is null, we need more data (save remainder in tail buffer)
         if ($data === null) {
            break;
         }
      }

      // @ Update Body metadata
      $Body->downloaded = $this->downloaded;

      // @ Check if all data received
      if ($Body->length !== null && $this->downloaded >= $Body->length) {
         $this->finish();
         return $Body->length;
      }

      return 0;
   }

   private function parseBoundaryStart (string $data): ?string
   {
      $boundary = $this->boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);
      if ($pos === false) {
         // @ Not enough data, save as tail buffer
         $this->tailBuffer = $data;
         return null;
      }

      // @ Check if this is the final boundary (--boundary--)
      $afterBoundary = $pos + $boundaryLen;
      if ($afterBoundary + 2 <= strlen($data)) {
         if (substr($data, $afterBoundary, 2) === '--') {
            // @ Final boundary: finish
            $this->finish();
            return '';
         }
      }

      // @ Skip boundary + \r\n
      $nextPos = $afterBoundary + 2; // +2 for \r\n
      if ($nextPos > strlen($data)) {
         $this->tailBuffer = $data;
         return null;
      }

      $this->state = self::STATE_PART_HEADER;
      $this->headerBuffer = '';

      return substr($data, $nextPos);
   }

   private function parsePartHeader (string $data): ?string
   {
      $this->headerBuffer .= $data;

      // @ Search for end of headers (\r\n\r\n)
      $headerEnd = strpos($this->headerBuffer, "\r\n\r\n");
      if ($headerEnd === false) {
         // @ Need more data
         return null;
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
                  $this->fileHandler = null;
                  $file['error'] = UPLOAD_ERR_CANT_WRITE;
               }
               else {
                  $this->fileHandler = $handle;
               }
            }
            catch (Throwable) {
               $this->fileHandler = null;
               $file['error'] = UPLOAD_ERR_CANT_WRITE;
            }
         }

         $this->filesKeys[] = $uploadKey;
         $this->files[] = $file; // @ Index will be updated with size at close

         $this->state = self::STATE_PART_BODY_FILE;
      }
      else if ($fieldName !== '') {
         $this->currentFieldName = $fieldName;
         $this->fieldBuffer = '';
         $this->state = self::STATE_PART_BODY_FIELD;
      }

      return $remainder;
   }

   private function parsePartBodyFile (string $data): ?string
   {
      $boundary = "\r\n" . $this->boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);

      if ($pos !== false) {
         // @ Boundary found: write everything before boundary to file
         $fileData = substr($data, 0, $pos);

         if ($this->fileHandler !== null && $fileData !== '') {
            fwrite($this->fileHandler, $fileData);
         }

         // @ Close file and finalize file entry
         $this->closeCurrentFile();

         // @ Move past boundary
         $afterBoundary = $pos + $boundaryLen;

         // @ Check if this is the final boundary (--)
         if ($afterBoundary + 2 <= strlen($data)) {
            if (substr($data, $afterBoundary, 2) === '--') {
               $this->finish();
               return '';
            }
         }

         // @ Skip \r\n after boundary
         $nextPos = $afterBoundary + 2; // +2 for \r\n
         if ($nextPos > strlen($data)) {
            $this->tailBuffer = substr($data, $afterBoundary);
            $this->state = self::STATE_BOUNDARY_START;
            return null;
         }

         $this->state = self::STATE_PART_HEADER;
         $this->headerBuffer = '';

         return substr($data, $nextPos);
      }

      // @ Boundary not found: write data except tail buffer to file
      // @ Keep enough bytes for boundary detection across chunks
      $safeLen = strlen($data) - $boundaryLen - 2;

      if ($safeLen > 0) {
         $safeData = substr($data, 0, $safeLen);

         if ($this->fileHandler !== null) {
            fwrite($this->fileHandler, $safeData);
         }

         $this->tailBuffer = substr($data, $safeLen);
      }
      else {
         // @ Not enough data to write safely, keep all in tail buffer
         $this->tailBuffer = $data;
      }

      return null;
   }

   private function parsePartBodyField (string $data): ?string
   {
      $boundary = "\r\n" . $this->boundary;
      $boundaryLen = strlen($boundary);

      // @ Search for boundary in data
      $pos = strpos($data, $boundary);

      if ($pos !== false) {
         // @ Boundary found: accumulate everything before boundary
         $this->fieldBuffer .= substr($data, 0, $pos);

         // @ Store field value
         $fieldName = $this->currentFieldName;
         if ($fieldName !== '') {
            $this->postEncoded .= urlencode($fieldName) . '=' . urlencode($this->fieldBuffer) . '&';
         }

         $this->fieldBuffer = '';

         // @ Move past boundary
         $afterBoundary = $pos + $boundaryLen;

         // @ Check if this is the final boundary (--)
         if ($afterBoundary + 2 <= strlen($data)) {
            if (substr($data, $afterBoundary, 2) === '--') {
               $this->finish();
               return '';
            }
         }

         // @ Skip \r\n after boundary
         $nextPos = $afterBoundary + 2;
         if ($nextPos > strlen($data)) {
            $this->tailBuffer = substr($data, $afterBoundary);
            $this->state = self::STATE_BOUNDARY_START;
            return null;
         }

         $this->state = self::STATE_PART_HEADER;
         $this->headerBuffer = '';

         return substr($data, $nextPos);
      }

      // @ Boundary not found: accumulate data except tail buffer
      $safeLen = strlen($data) - $boundaryLen - 2;

      if ($safeLen > 0) {
         $this->fieldBuffer .= substr($data, 0, $safeLen);
         $this->tailBuffer = substr($data, $safeLen);
      }
      else {
         $this->tailBuffer = $data;
      }

      return null;
   }

   /**
    * Close the current file handler and update file size.
    */
   private function closeCurrentFile (): void
   {
      $fileIndex = count($this->files) - 1;
      if ($fileIndex < 0) return;

      // @ Update file size and close handler
      $file = &$this->files[$fileIndex];

      if ($this->fileHandler !== null) {
         // @ fstat returns size of data written so far (before the last fwrite in the caller)
         // The lastWriteSize was already written before this method is called,
         // so fstat after the write gives the total
         $stat = fstat($this->fileHandler);
         $file['size'] = $stat !== false ? $stat['size'] : 0;

         try {
            fclose($this->fileHandler);
         }
         catch (Throwable) {}

         $this->fileHandler = null;
      }
      else {
         $file['size'] = 0;
         $file['error'] = UPLOAD_ERR_NO_FILE;
      }
   }

   /**
    * Finalize: populate $_FILES and $_POST, reset state.
    */
   private function finish (): void
   {
      // @ Close any open file handler
      if ($this->fileHandler !== null) {
         try {
            fclose($this->fileHandler);
         }
         catch (Throwable) {}
         $this->fileHandler = null;
      }

      // @ Populate $_POST
      if ($this->postEncoded !== '') {
         parse_str($this->postEncoded, $_POST);
      }

      // @ Populate $_FILES
      if (! empty($this->files)) {
         $filesEncoded = '';

         foreach ($this->files as $index => $file) {
            $uploadKey = $this->filesKeys[$index] ?? '';
            $filesEncoded .= urlencode($uploadKey) . '=' . $index . '&';
         }

         if ($filesEncoded !== '') { // @phpstan-ignore notIdentical.alwaysTrue
            $filesData = $this->files;
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

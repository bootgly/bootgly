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
use const UPLOAD_ERR_FORM_SIZE;
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
use function ltrim;
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
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
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
   private int $currentFileSize;
   private int $currentFieldSize;
   private int $fieldsCount;
   private int $filesCount;
   private int $bytesSinceDiskCheck;
   private bool $rejected;

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
      $this->currentFileSize = 0;
      $this->currentFieldSize = 0;
      $this->fieldsCount = 0;
      $this->filesCount = 0;
      $this->bytesSinceDiskCheck = 0;
      $this->rejected = false;
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
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Body = $Request->Body;

      // ! Reject helper: centralize cleanup and rejection logic
      $reject = function (string $raw) use ($Package, $Request): void {
         if ($this->rejected) {
            return;
         }

         $this->rejected = true;
         $this->tailBuffer = '';
         $this->headerBuffer = '';
         $this->fieldBuffer = '';
         $this->postEncoded = '';

         if ($this->fileHandler !== null) {
            try {
               fclose($this->fileHandler);
            }
            catch (Throwable) {}

            $this->fileHandler = null;
         }

         $Request->Body->waiting = false;

         if ($Package instanceof TCP_Packages) {
            $Package->reject($raw);
         }
      };
      // ! Append field data with size check, centralize logic for both boundary and non-boundary accumulation
      $appendField = function (string $chunk) use ($reject): bool {
         if ($chunk === '') {
            return true;
         }

         $length = strlen($chunk);
         if ($this->currentFieldSize + $length > Server\Request::$maxMultipartFieldSize) {
            $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return false;
         }

         $this->fieldBuffer .= $chunk;
         $this->currentFieldSize += $length;

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

      // @@ Process data through state machine
      while ($data !== '') {
         switch ($this->state) {
            case self::STATE_BOUNDARY_START:
               $boundary = $this->boundary;
               $boundaryLen = strlen($boundary);

               // @ Search for boundary in data
               $pos = strpos($data, $boundary);
               if ($pos === false) {
                  // @ Not enough data, save as tail buffer
                  $this->tailBuffer = $data;
                  $data = null;
                  break;
               }

               // @ Check if this is the final boundary (--boundary--)
               $afterBoundary = $pos + $boundaryLen;
               if ($afterBoundary + 2 <= strlen($data)) {
                  if (substr($data, $afterBoundary, 2) === '--') {
                     // @ Final boundary: finish
                     $this->finish();
                     $data = '';
                     break;
                  }
               }

               // @ Skip boundary + \r\n
               $nextPos = $afterBoundary + 2; // +2 for \r\n
               if ($nextPos > strlen($data)) {
                  $this->tailBuffer = $data;
                  $data = null;
                  break;
               }

               $this->state = self::STATE_PART_HEADER;
               $this->headerBuffer = '';

               $data = substr($data, $nextPos);
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
                        $file['type'] = trim($value);
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

                  if (($file['error'] ?? 0) === 0) {
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
                  }

                  $this->currentFileSize = 0;
                  $this->bytesSinceDiskCheck = 0;

                  $this->filesKeys[] = $uploadKey;
                  $this->files[] = $file; // @ Index will be updated with size at close

                  $this->state = self::STATE_PART_BODY_FILE;
               }
               else if ($fieldName !== '') {
                  if ($this->fieldsCount >= Server\Request::$maxMultipartFields) {
                     $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                     $data = '';
                     break;
                  }
                  $this->fieldsCount++;

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

               // @ Search for boundary in data
               $pos = strpos($data, $boundary);

               if ($pos !== false) {
                  // @ Boundary found: write everything before boundary to file
                  $fileData = substr($data, 0, $pos);

                  $this->writeFileChunk($fileData);

                  // @ Close file and finalize file entry
                  $this->closeCurrentFile();

                  // @ Move past boundary
                  $afterBoundary = $pos + $boundaryLen;

                  // @ Check if this is the final boundary (--)
                  if ($afterBoundary + 2 <= strlen($data)) {
                     if (substr($data, $afterBoundary, 2) === '--') {
                        $this->finish();
                        $data = '';
                        break;
                     }
                  }

                  // @ Skip \r\n after boundary
                  $nextPos = $afterBoundary + 2; // +2 for \r\n
                  if ($nextPos > strlen($data)) {
                     $this->tailBuffer = substr($data, $afterBoundary);
                     $this->state = self::STATE_BOUNDARY_START;
                     $data = null;
                     break;
                  }

                  $this->state = self::STATE_PART_HEADER;
                  $this->headerBuffer = '';

                  $data = substr($data, $nextPos);
                  break;
               }

               // @ Boundary not found: write data except tail buffer to file
               // @ Keep enough bytes for boundary detection across chunks
               $safeLen = strlen($data) - $boundaryLen - 2;

               if ($safeLen > 0) {
                  $safeData = substr($data, 0, $safeLen);

                  $this->writeFileChunk($safeData);

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

               // @ Search for boundary in data
               $pos = strpos($data, $boundary);

               if ($pos !== false) {
                  // @ Boundary found: accumulate everything before boundary
                  if ($appendField(substr($data, 0, $pos)) === false) {
                     $data = '';
                     break;
                  }

                  // @ Store field value
                  $fieldName = $this->currentFieldName;
                  if ($fieldName !== '') {
                     $this->postEncoded .= urlencode($fieldName) . '=' . urlencode($this->fieldBuffer) . '&';
                  }

                  $this->fieldBuffer = '';
                  $this->currentFieldSize = 0;

                  // @ Move past boundary
                  $afterBoundary = $pos + $boundaryLen;

                  // @ Check if this is the final boundary (--)
                  if ($afterBoundary + 2 <= strlen($data)) {
                     if (substr($data, $afterBoundary, 2) === '--') {
                        $this->finish();
                        $data = '';
                        break;
                     }
                  }

                  // @ Skip \r\n after boundary
                  $nextPos = $afterBoundary + 2;
                  if ($nextPos > strlen($data)) {
                     $this->tailBuffer = substr($data, $afterBoundary);
                     $this->state = self::STATE_BOUNDARY_START;
                     $data = null;
                     break;
                  }

                  $this->state = self::STATE_PART_HEADER;
                  $this->headerBuffer = '';

                  $data = substr($data, $nextPos);
                  break;
               }

               // @ Boundary not found: accumulate data except tail buffer
               $safeLen = strlen($data) - $boundaryLen - 2;

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

         if ($this->rejected) {
            return 0;
         }

         // @ If data is null, we need more data (save remainder in tail buffer)
         if ($data === null) {
            break;
         }
      }

      // @ Update Body metadata
      $Body->downloaded = $this->downloaded;

      if ($this->rejected) {
         return 0;
      }

      // @ Check if all data received
      if ($Body->length !== null && $this->downloaded >= $Body->length) {
         $this->finish();
         return $Body->length;
      }

      return 0;
   }

   /**
    * Stream file bytes with runtime safety checks.
    */
   private function writeFileChunk (string $chunk): void
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
         $this->closeCurrentFile();
         return;
      }

      // ! Re-check free disk space periodically while streaming.
      $this->bytesSinceDiskCheck += $chunkLength;
      if ($this->bytesSinceDiskCheck >= 1048576) {
         try {
            $freeSpace = disk_free_space(BOOTGLY_WORKING_BASE . '/workdata/temp/files/downloaded/');
            if ($freeSpace !== false && $freeSpace < 1048576) {
               $file['error'] = UPLOAD_ERR_CANT_WRITE;
               $this->closeCurrentFile();
               return;
            }
         } catch (Throwable) {
            $file['error'] = UPLOAD_ERR_CANT_WRITE;
            $this->closeCurrentFile();
            return;
         }

         $this->bytesSinceDiskCheck = 0;
      }

      $written = fwrite($this->fileHandler, $chunk);
      if ($written === false) {
         $file['error'] = UPLOAD_ERR_CANT_WRITE;
         $this->closeCurrentFile();
         return;
      }

      if ($written > 0) {
         $this->currentFileSize += $written;
         $file['size'] = $this->currentFileSize;
      }

      if ($written !== $chunkLength) {
         $file['error'] = UPLOAD_ERR_CANT_WRITE;
         $this->closeCurrentFile();
      }
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
    * Finalize: populate $_FILES and $_POST, reset state.
    */
   private function finish (): void
   {
      if ($this->rejected) {
         return;
      }

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

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;


class Downloader
{
   public Body $Body;

   // ***


   public function __construct (Request $Request)
   {
      $this->Body = &$Request->Raw->Body;

      // ***
   }

   public function downloading (string $boundary)
   {
      $postEncoded = '';
      $filesEncoded = '';

      $files = [];

      $sectionStart = strlen($boundary) + 2;
      $maxClientFiles = 1024;

      while ($maxClientFiles-- > 0 && $sectionStart > 0) {
         $sectionStart = $this->download($boundary, $sectionStart, $postEncoded, $filesEncoded, $files);
      }

      if ($postEncoded) {
         parse_str($postEncoded, $_POST);
      }

      if ($filesEncoded) {
         parse_str($filesEncoded, $_FILES);
         array_walk_recursive($_FILES, function (&$value) use ($files) {
            $value = $files[$value];
         });
      }
   }
   public function download ($boundary, $sectionStart, &$postEncoded, &$filesEncoded, &$files)
   {
      $Body = $this->Body; // @ Instance Request Body

      // @ Check if Body downloaded length is minor than Section start position
      if ($Body->downloaded < $sectionStart) {
         $Body->waiting = true;
         return 0;
      }
      // @ Check if Body downloaded length is minor than Body length
      if ($Body->downloaded < $Body->length) {
         $Body->waiting = true;
         return 0;
      }

      // @ Set Section end position
      // @ Check if boundary exists in the end of Section
      $sectionEnd = strpos($Body->raw, "\r\n$boundary", $sectionStart);
      if (! $sectionEnd) {
         $Body->waiting = false;
         return 0;
      }
      // @ Set content lines end position
      // @ Check if content lines end position exists
      $contentLinesEnd = strpos($Body->raw, "\r\n\r\n", $sectionStart);
      if (! $contentLinesEnd || $contentLinesEnd + 4 > $sectionEnd) {
         $Body->waiting = false;
         return 0;
      }

      $Body->waiting = false;

      $contentLinesRaw = substr($Body->raw, $sectionStart, $contentLinesEnd - $sectionStart);
      $contentLines = explode("\r\n", trim($contentLinesRaw . "\r\n"));

      $boundaryValue = substr($Body->raw, $contentLinesEnd + 4, $sectionEnd - $contentLinesEnd - 4);

      $uploadKey = false;
      $file = [];

      foreach ($contentLines as $contentLine) {
         if (! strpos($contentLine, ': ') ) {
            return 0;
         }

         @[$key, $value] = explode(': ', $contentLine);

         // Form-data
         switch ( strtolower($key) ) {
            case 'content-disposition':
               // @ File
               if ( preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match) ) {
                  // Parse $_FILES
                  $error = 0;
                  $tempFile = '';
                  $size = \strlen($boundaryValue);
                  $tempUploadedDir = BOOTGLY_WORKING_BASE . '/workdata/temp/files/downloaded/';

                  if (! $tempUploadedDir) {
                     $error = UPLOAD_ERR_NO_TMP_DIR;
                  }
                  else if ($boundaryValue === '') {
                     $error = UPLOAD_ERR_NO_FILE;
                  }
                  else {
                     $tempFile = tempnam($tempUploadedDir, '');

                     if ($tempFile === false || file_put_contents($tempFile, $boundaryValue) === false) {
                        $error = UPLOAD_ERR_CANT_WRITE;
                     }
                  }

                  $uploadKey = $match[1];

                  $file = [
                     'name' => $match[2],
                     'tmp_name' => $tempFile,
                     'size' => $size,
                     'error' => $error,
                     'type' => '',
                  ];

                  break;
               }
               // @ Text
               else {
                  // Parse $_POST
                  if ( preg_match('/name="(.*?)"$/', $value, $match) ) {
                     $k = $match[1];
                     $postEncoded .= urlencode($k) . "=" . urlencode($boundaryValue) . '&';
                  }

                  return $sectionEnd + strlen($boundary) + 2;
               }

               break;
            case "content-type":
               $file['type'] = trim($value);

               break;
         }
      }

      if ($uploadKey === false) {
         return 0;
      }

      $filesEncoded .= urlencode($uploadKey) . '=' . count($files) . '&';
      $files[] = $file;

      return $sectionEnd + strlen($boundary) + 2;
   }
}

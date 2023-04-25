<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Request;


use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Request\_\Header;
use Bootgly\Web\HTTP\Server\Request\_\Content;


class Downloader
{
   public Content $Content;

   // ***


   public function __construct (Request $Request)
   {
      $this->Content = &$Request->Content;

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
      $Content = $this->Content; // @ Instance Request Content

      // @ Check if Content downloaded length is minor than Section start position
      if ($Content->downloaded < $sectionStart) {
         $Content->waiting = true;
         return 0;
      }

      // @ Check if Content downloaded length is minor than Content length
      if ($Content->downloaded < $Content->length) {
         $Content->waiting = true;
         return 0;
      }

      // @ Set Section end position
      // @ Check if boundary exists in the end of Section
      $sectionEnd = strpos($Content->raw, "\r\n$boundary", $sectionStart);
      if (! $sectionEnd) {
         $Content->waiting = false;
         return 0;
      }

      // @ Set content lines end position
      // @ Check if content lines end position exists
      $contentLinesEnd = strpos($Content->raw, "\r\n\r\n", $sectionStart);
      if (! $contentLinesEnd || $contentLinesEnd + 4 > $sectionEnd) {
         $Content->waiting = false;
         return 0;
      }

      $Content->waiting = false;

      $contentLinesRaw = substr($Content->raw, $sectionStart, $contentLinesEnd - $sectionStart);
      $contentLines = explode("\r\n", trim($contentLinesRaw . "\r\n"));

      $boundaryValue = substr($Content->raw, $contentLinesEnd + 4, $sectionEnd - $contentLinesEnd - 4);

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
                  $tempUploadedDir = HOME_BASE . '/workspace/temp/';

                  if (! $tempUploadedDir) {
                     $error = UPLOAD_ERR_NO_TMP_DIR;
                  } else if ($boundaryValue === '') {
                     $error = UPLOAD_ERR_NO_FILE;
                  } else {
                     $tempFile = tempnam($tempUploadedDir, 'server.http.downloaded.');

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

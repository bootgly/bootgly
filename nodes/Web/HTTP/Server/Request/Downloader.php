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


class Downloader
{
   public Request $Request;

   // * Data
   public array $posts;
   public array $files;


   public function __construct (Request $Request)
   {
      $this->Request = $Request;

      // * Data
      $this->posts = [];
      $this->files = [];
   }

   public function parse ()
   {
      $matched = preg_match(
         '/boundary="?(\S+)"?/',
         $this->Request->Header->get('Content-Type'),
         $match
      );

      if ($matched === 1) {
         $boundary = trim('--' . $match[1], '"');

         $this->downloading($boundary);

         return;
      }
   }
   public function downloading (string $boundary)
   {
      $postEncoded = '';

      $filesEncoded = '';
      $files = [];

      $sectionStart = strlen($boundary) + 4 + 2;
      $maxClientFiles = 1024;

      while ($maxClientFiles-- > 0 && $sectionStart) {
         $sectionStart = $this->download($boundary, $sectionStart, $postEncoded, $filesEncoded, $files);
      }

      if ($postEncoded) {
         parse_str($postEncoded, $this->posts);
      }

      if ($filesEncoded) {
         parse_str($filesEncoded, $this->files);
         array_walk_recursive($this->files, function (&$value) use ($files) {
            $value = $files[$value];
         });
      }
   }
   public function download ($boundary, $sectionStart, &$postEncoded, &$filesEncoded, &$files)
   {
      $file = [];
      $boundary = "\r\n$boundary";
      $content = $this->Request->Content->raw;

      if (strlen($content) < $sectionStart) {
         return 0;
      }

      $sectionEnd = strpos($content, $boundary, $sectionStart);
      if (! $sectionEnd) {
         return 0;
      }

      $contentLinesEnd = strpos($content, "\r\n\r\n", $sectionStart);
      if (! $contentLinesEnd || $contentLinesEnd + 4 > $sectionEnd) {
         return 0;
      }

      $contentLinesRaw = substr($content, $sectionStart, $contentLinesEnd - $sectionStart);
      $contentLines = explode("\r\n", trim($contentLinesRaw . "\r\n"));

      $boundaryValue = substr($content, $contentLinesEnd + 4, $sectionEnd - $contentLinesEnd - 4);

      $uploadKey = false;

      foreach ($contentLines as $contentLine) {
         if (! strpos($contentLine, ': ') ) {
            return 0;
         }

         @[$key, $value] = explode(': ', $contentLine);

         switch ( strtolower($key) ) {
            case 'content-disposition':
               // Form-data
               if ( preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match) ) {
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

                  // Parse upload files.
                  $file = [
                     'name' => $match[2],
                     'tmp_name' => $tempFile,
                     'size' => $size,
                     'error' => $error,
                     'type' => '',
                  ];

                  break;
               } else { // Is post field
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

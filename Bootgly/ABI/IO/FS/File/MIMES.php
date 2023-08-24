<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\FS\File;


trait MIMES
{
   public const EXTENSIONS_TO_MIME = [
      // @ commons
      'php' => 'text/html',
      'css' => 'text/css',
      'html' => 'text/html',
      'js' => 'application/javascript',
      'txt' => 'text/plain',
      'csv' => 'text/csv',
      'xml' => 'text/xml',
      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'ico' => 'image/x-icon',
      'webp' => 'image/webp',
      'woff' => 'application/x-font-woff',
      'woff2' => 'application/x-font-woff',
      'json' => 'application/json',
      'pdf' => 'application/pdf',
      'zip' => 'application/zip',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'mp3' => 'audio/mpeg',
      'mp4' => 'video/mp4',

      // application/*
      'eot' => 'application/vnd.ms-fontobject',
      'ttf' => 'application/x-font-ttf',
      'map' => 'application/x-navimap',
      'rar' => 'application/x-rar-compressed',
      '7z' => 'application/x-7z-compressed',
      'exe' => 'application/x-msdownload',
      'doc' => 'application/msword',
      'dot' => 'application/msword',
      'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
      'xls' => 'application/vnd.ms-excel',
      'xlt' => 'application/vnd.ms-excel',
      'xla' => 'application/vnd.ms-excel',
      'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pot' => 'application/vnd.ms-powerpoint',
      'pps' => 'application/vnd.ms-powerpoint',
      'ppa' => 'application/vnd.ms-powerpoint',
      'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
      'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
      // ...

      // audio/*
      'ogg' => 'audio/ogg',
      'wav' => 'audio/wav',
      // ...

      // image/*
      'svg' => 'image/svg+xml',
      // ...

      // text/*
      'less' => 'text/css',
      // ...

      // video/*
      'webm' => 'video/webm',
      // ...
   ];
}

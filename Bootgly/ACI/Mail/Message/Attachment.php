<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\Message;


use function function_exists;
use function strpbrk;
use InvalidArgumentException;

use Bootgly\ABI\IO\FS\File;


/**
 * A mail attachment: raw bytes plus the metadata that becomes its MIME
 * part headers. Built from a `File` (name/type detected) or from raw
 * bytes (name required). `INLINE` disposition + `cid` mark an embedded
 * (multipart/related) part.
 */
final class Attachment
{
   public const string ATTACHMENT = 'attachment';
   public const string INLINE = 'inline';

   // * Data
   /**
    * File name presented to the recipient.
    */
   public readonly string $name;
   /**
    * Full MIME type (e.g. `image/png`).
    */
   public readonly string $type;
   /**
    * Raw bytes.
    */
   public readonly string $contents;
   /**
    * `self::ATTACHMENT` or `self::INLINE`.
    */
   public readonly string $disposition;
   /**
    * Content-ID ('' for regular attachments).
    */
   public readonly string $cid;


   public function __construct (
      File|string $source,
      string $name = '',
      string $type = '',
      string $disposition = self::ATTACHMENT,
      string $cid = ''
   ) {
      // ? Guard: disposition
      if ($disposition !== self::ATTACHMENT && $disposition !== self::INLINE) {
         throw new InvalidArgumentException(
            "Invalid mail attachment disposition: `{$disposition}`."
         );
      }

      // @ File source — name/type detected from the file
      if ($source instanceof File) {
         $contents = $source->exists === true ? $source->contents : false;

         // ? Guard: readability
         if ($contents === false) {
            throw new InvalidArgumentException(
               "Mail attachment file `{$source->Path->current}` is not readable."
            );
         }

         if ($name === '') {
            $name = $source->Path->current;
         }
         if ($type === '') {
            // ? fileinfo is enabled by default but undeclared — guard it
            $type = function_exists('mime_content_type') === true
               ? ($source->MIME->type ?? '')
               : '';
         }
      }
      // @ Raw bytes source
      else {
         // ? Guard: raw bytes carry no natural name
         if ($name === '') {
            throw new InvalidArgumentException(
               'Mail attachment from raw bytes requires a name.'
            );
         }

         $contents = $source;
      }

      // ? Guard: header-bound fields must carry no control bytes
      if (
         strpbrk($name, "\r\n\0") !== false
         || strpbrk($type, "\r\n\0") !== false
         || strpbrk($cid, "\r\n\0") !== false
      ) {
         throw new InvalidArgumentException(
            'Mail attachment name/type/cid contains illegal control bytes.'
         );
      }

      // * Data
      $this->name = $name;
      $this->type = $type !== '' ? $type : 'application/octet-stream';
      $this->contents = $contents;
      $this->disposition = $disposition;
      $this->cid = $cid;
   }
}

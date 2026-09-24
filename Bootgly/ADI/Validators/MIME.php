<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validators;


use const BOOTGLY_UPLOADS_DIR;
use function array_map;
use function dirname;
use function function_exists;
use function in_array;
use function is_array;
use function is_file;
use function is_link;
use function is_string;
use function realpath;
use function strcspn;
use function strtolower;
use function substr;
use function trim;
use RuntimeException;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ADI\Validation\Condition;


/**
 * Upload rule: the file's actual content must be one of the allowed MIME types.
 *
 * The type is sniffed from the bytes the server received (libmagic, through
 * the `fileinfo` extension) — the record's `type` is the client's declared
 * hint and is never read. Only a regular file directly inside
 * `BOOTGLY_UPLOADS_DIR`, where the HTTP server streams multipart uploads, is
 * inspected: a record whose `tmp_name` points anywhere else fails, so a
 * hand-built record cannot turn the rule into a probe of the filesystem.
 *
 * A sniff names the format the bytes start with; it does not prove the file
 * is harmless. A polyglot (`GIF89a<?php …`) sniffs as its image type, and an
 * SVG may carry scripts: never serve uploads from an executable or web path.
 * List types as libmagic names them (`image/jpeg`, not `image/jpg`).
 */
class MIME extends Condition
{
   // * Config
   /**
    * Allowed MIME types, normalized: lowercased, without parameters.
    *
    * @var array<int,string>
    */
   public private(set) array $types;

   // * Metadata
   protected string $template = '{field} must have an allowed MIME type.';


   /**
    * @param string|array<int,string> $types Allowed MIME types (compared case-insensitively, parameters ignored).
    *
    * @throws RuntimeException When the `fileinfo` extension is not available.
    */
   public function __construct (string|array $types, string $message = '')
   {
      // ? Sniffing needs fileinfo — never build a rule that fails or passes every upload silently
      if (function_exists('mime_content_type') === false) {
         throw new RuntimeException('The MIME validator requires the fileinfo extension.');
      }

      parent::__construct($message);

      // * Config
      $this->types = array_map(
         static fn (string $type): string => self::normalize($type),
         is_array($types) ? $types : [$types]
      );
   }

   /**
    * Pass when the uploaded file's sniffed type is allowed.
    *
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      // ?
      if (is_array($value) === false || ($value['error'] ?? null) !== 0) {
         return false;
      }

      // ! The declared `type` is a client hint: only the received bytes count
      $path = $value['tmp_name'] ?? null;
      if (is_string($path) === false) {
         return false;
      }

      // ? Only a regular file the server wrote — checked before `realpath()`,
      //   which throws on a NUL byte
      $directory = realpath(BOOTGLY_UPLOADS_DIR);
      if ($directory === false || is_link($path) || is_file($path) === false) {
         return false;
      }
      $file = realpath($path);
      if ($file === false || dirname($file) !== $directory) {
         return false;
      }

      // @ Sniff the resolved path — never the client string again
      $type = self::normalize(new File\MIME($file)->type);

      // :
      return $type !== '' && in_array($type, $this->types, true);
   }

   /**
    * Lowercase a MIME type and drop its parameters (`Image/PNG; x=y` → `image/png`).
    */
   private static function normalize (string $type): string
   {
      return strtolower(trim(substr($type, 0, strcspn($type, ';'))));
   }
}

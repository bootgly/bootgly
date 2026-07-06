<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XML1;
use function get_object_vars;
use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_replace;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in XML response formatter.
 *
 * Minimal, dependency-free array→XML encoder (no ext-xmlwriter): root
 * `<response>`; associative keys become elements, numeric keys `<item>`;
 * scalars are text (XML-escaped), booleans `true`/`false`, null an empty
 * element. Objects expose only their public properties (json_encode parity);
 * nesting past 64 levels truncates to an empty element (cycle-safe).
 * Non-name-safe keys are sanitized to valid XML element names.
 */
class XML extends Resource
{
   // * Config
   // ...

   // * Data
   protected Response $Response;

   // * Metadata
   // ...


   public function __construct (Response $Response)
   {
      parent::__construct(persistent: true);

      // * Data
      $this->Response = $Response;
   }

   /**
    * Send XML content through the canonical Response sender.
    */
   public function send (mixed $body = null): Response
   {
      // ! Set the default media type instead of a header field — mirrors JSON:
      //   leaves fields/prepared empty so build() keeps its fast path + the Raw
      //   wire-cache. An explicit Content-Type still wins.
      $this->Response->Header->type = 'application/xml';

      // ? Pre-encoded XML string passes through untouched.
      if (is_string($body) && $body !== '') {
         return $this->Response->send($body);
      }

      $xml = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<response>' . self::encode($body) . '</response>';

      // :
      return $this->Response->send($xml);
   }

   /**
    * Encode one value as the inner XML of its element.
    */
   private static function encode (mixed $value, int $depth = 0): string
   {
      // ? Depth cap — a reference cycle would recurse forever and kill the
      //   worker (json_encode fails gracefully on recursion; mirror that by
      //   truncating to an empty element past 64 levels).
      if ($depth >= 64) {
         return '';
      }

      // @ Arrays — associative keys become elements, numeric keys `<item>`
      if (is_array($value)) {
         $xml = '';
         foreach ($value as $key => $item) {
            $tag = is_int($key) ? 'item' : self::name((string) $key);
            $xml .= "<{$tag}>" . self::encode($item, $depth + 1) . "</{$tag}>";
         }

         return $xml;
      }

      // @ Objects — only their PUBLIC properties, matching json_encode: a
      //   `(array)` cast would also expose private/protected members, letting
      //   the client pick the leakier representation via the Accept header.
      if (is_object($value)) {
         return self::encode(get_object_vars($value), $depth + 1);
      }

      // ? null → empty element
      if ($value === null) {
         return '';
      }

      // ? bool → literal true/false
      if (is_bool($value)) {
         return $value ? 'true' : 'false';
      }

      // @ scalar → XML-escaped text
      if (is_scalar($value)) {
         return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }

      // : Unsupported type (resource, closure) → empty element
      return '';
   }

   /**
    * Sanitize one array key into a valid XML element name.
    */
   private static function name (string $key): string
   {
      // @ Replace every character that is not a valid XML name char
      $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);

      // ? Empty after sanitization → generic element
      if ($name === '' || $name === null) {
         return 'item';
      }

      // ? XML names must start with a letter or underscore
      if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
         $name = "_{$name}";
      }

      // :
      return $name;
   }
}

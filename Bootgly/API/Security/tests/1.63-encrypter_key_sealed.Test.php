<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\EncrypterKeySealed;


use const OPENSSL_RAW_DATA;
use function assert;
use function base64_encode;
use function get_mangled_object_vars;
use function implode;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function openssl_decrypt;
use function print_r;
use function serialize;
use function str_contains;
use function strlen;
use function substr;
use function unserialize;
use function var_dump;
use function var_export;
use LogicException;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;


/**
 * The control: key material kept as a plain private string, as a Key held it
 * before it was sealed.
 */
final class PlainHolder
{
   // * Data
   private string $material;


   public function __construct (string $material)
   {
      // * Data
      $this->material = $material;
   }

   /**
    * Check the material this holder keeps.
    */
   public function check (string $material): bool
   {
      return $this->material === $material;
   }
}


/**
 * The raw material of an `Encrypter\Key` sits sealed in a
 * `SensitiveParameterValue`: `var_export`, the `(array)` cast and
 * `get_mangled_object_vars` — which bypass `__debugInfo()` — never reach its
 * bytes, while the same channels show a plain private string. The key still
 * seals and opens as before, with the exact bytes it was given, its dumps
 * show its id, and both serialization directions stay refused.
 */
return new Test(
   description: 'Encrypter\Key: the sealed material stays out of var_export and the array cast, and still seals and opens',
   test: function () {
      // ! The key, its twin from the same material, and the control
      $raw = 'SYNTH-ENCRYPTER-KEY-0123456789ab';
      $Key = new Key($raw, 'k-visible');
      $Plain = new PlainHolder($raw);

      // ! The channels that read an object's properties without __debugInfo()
      $Channels = [
         'var_export' => static fn (object $Object): string => var_export($Object, true),
         'var_export of the array cast' => static fn (object $Object): string => var_export((array) $Object, true),
         'print_r of the array cast' => static fn (object $Object): string => print_r((array) $Object, true),
         'var_dump of the array cast' => static function (object $Object): string {
            ob_start();
            var_dump((array) $Object);

            return (string) ob_get_clean();
         },
         'get_mangled_object_vars' => static fn (object $Object): string => var_export(get_mangled_object_vars($Object), true),
      ];

      // @@ Each channel: the Key never shows the material, the plain holder always does
      $leaks = [];
      $blind = [];
      foreach ($Channels as $channel => $Channel) {
         if (str_contains($Channel($Key), $raw) === true) {
            $leaks[] = $channel;
         }
         if (str_contains($Channel($Plain), $raw) === false) {
            $blind[] = $channel;
         }
      }
      $leaked = implode(', ', $leaks);
      $unseen = implode(', ', $blind);
      yield assert(
         assertion: $leaks === [] && $blind === [] && $Plain->check($raw) === true,
         description: "var_export, the array cast and get_mangled_object_vars never reach the sealed material (leaked: [{$leaked}]) and show a plain private one (blind: [{$unseen}])"
      );

      // @ JSON and the debug dump: the id, never the material
      $dump = print_r($Key, true);
      yield assert(
         assertion: json_encode($Key) === '{"id":"k-visible"}'
            && str_contains($dump, 'k-visible') === true
            && str_contains($dump, Secret::MASK) === true
            && str_contains($dump, $raw) === false,
         description: 'JSON carries only the id, and a debug dump shows the id and the mask in place of the material'
      );

      // @ The sealed material is live: the key, its twin, an import and OpenSSL itself open what it sealed
      $sealed = $Key->seal('payload', 'aad');
      $IV = substr($sealed, 0, 12);
      $ciphertext = substr($sealed, 12, -16);
      $tag = substr($sealed, -16);
      yield assert(
         assertion: $Key->open($sealed, 'aad') === 'payload'
            && (new Key($raw))->open($sealed, 'aad') === 'payload'
            && Key::import(base64_encode($raw))->open($sealed, 'aad') === 'payload'
            && openssl_decrypt($ciphertext, 'aes-256-gcm', $raw, OPENSSL_RAW_DATA, $IV, $tag, 'aad') === 'payload'
            && (new Key('SYNTH-ENCRYPTER-OTHER-0123456789'))->open($sealed, 'aad') === null
            && $Key->open($sealed, 'other-aad') === null,
         description: 'The key seals with the exact bytes it was given: it, a twin, an import and raw OpenSSL open it; another key or AAD does not'
      );

      // @ The Encrypter round trip across two keys of the same material
      $Sender = new Encrypter($Key);
      $Receiver = new Encrypter(new Key($raw, 'k-visible'));
      yield assert(
         assertion: $Receiver->decrypt($Sender->encrypt('payload', 'context'), 'context') === 'payload',
         description: 'An Encrypter decrypts what another Encrypter sealed with the same material'
      );

      // @ Both serialization directions are still refused
      $serialized = 'serialized';
      try {
         serialize($Key);
      }
      catch (Throwable $Throwable) {
         $serialized = $Throwable instanceof LogicException ? 'refused' : $Throwable::class;
      }
      $class = Key::class;
      $length = strlen($class);
      $unserialized = 'unserialized';
      try {
         unserialize("O:{$length}:\"{$class}\":0:{}");
      }
      catch (Throwable $Throwable) {
         $unserialized = $Throwable instanceof LogicException ? 'refused' : $Throwable::class;
      }
      yield assert(
         assertion: $serialized === 'refused' && $unserialized === 'refused',
         description: "A key refuses serialize ({$serialized}) and unserialize ({$unserialized})"
      );
   }
);

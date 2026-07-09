<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Encrypter;


use InvalidArgumentException;


/**
 * Encryption key collection with a primary key and id-based rotation.
 */
class Keyring
{
   // * Config
   /**
    * Key used to encrypt new payloads.
    */
   public private(set) Key $Primary;

   // * Data
   /**
    * Decryption keys indexed by id.
    *
    * @var array<string,Key>
    */
   private array $Keys = [];
   /**
    * Single key without an id, used for id-less envelopes.
    *
    * Key rotation must use explicit ids. The no-id slot stays single by
    * design so decryption never guesses between defaults.
    */
   private null|Key $Default = null;

   // * Metadata
   // ...


   /**
    * Create a keyring. The first key becomes the primary.
    */
   public function __construct (Key $Key, Key ...$Keys)
   {
      // * Config
      $this->Primary = $Key;

      // ! Register the primary and every additional decryption key.
      $this->add($Key);
      foreach ($Keys as $Additional) {
         $this->add($Additional);
      }
   }

   /**
    * Add a decrypt-only key to the keyring.
    *
    * @throws InvalidArgumentException When the key id is duplicated.
    */
   public function add (Key $Key): self
   {
      // ? Single no-id slot
      if ($Key->id === null) {
         if ($this->Default !== null) {
            throw new InvalidArgumentException('Duplicate default Encrypter key. Use ids for rotation.');
         }

         $this->Default = $Key;

         return $this;
      }

      // ? Unique key ids only
      if (isset($this->Keys[$Key->id])) {
         throw new InvalidArgumentException('Duplicate Encrypter key id.');
      }

      $this->Keys[$Key->id] = $Key;

      return $this;
   }

   /**
    * Promote a new primary key, keeping the previous ones for decryption.
    *
    * @throws InvalidArgumentException When the key id conflicts with a registered key.
    */
   public function rotate (Key $Key): self
   {
      // @ Register first — conflicts throw before the primary changes.
      $this->add($Key);

      $this->Primary = $Key;

      return $this;
   }

   /**
    * Resolve the decryption key for an envelope key id.
    */
   public function resolve (null|string $id): null|Key
   {
      // ?: Id-less envelopes decrypt only with the no-id slot
      if ($id === null) {
         return $this->Default;
      }

      if ($id === '') {
         return null;
      }

      // : Indexed lookup.
      return $this->Keys[$id] ?? null;
   }
}

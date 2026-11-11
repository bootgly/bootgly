<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Password;


use SensitiveParameter;


/**
 * Verifier of a stored-hash format the native password algorithms cannot
 * read — hashes imported from another stack (PBKDF2 strings, salted digests).
 *
 * `Password` asks its verifiers only for a stored hash that
 * `password_verify()` did not accept and that names no native algorithm: a
 * hash `password_hash()` can mint (bcrypt `$2y$`, argon2i, argon2id) never
 * reaches a verifier, and neither does an empty one — so a legacy row PHP
 * names an algorithm for (any row starting `$argon2i$` / `$argon2id$`, a
 * `$2y$` row of exactly 60 bytes) must be re-prefixed in the store before
 * a verifier can read it. A hash that passes a verifier never conforms to the
 * current policy, so `Password::inspect()` answers with a fresh argon2id
 * hash to persist — the hash of the password AS TYPED, so a verifier that
 * accepts more than one spelling of a password moves the account to the
 * spelling used at that sign-in.
 *
 * An implementation is a security surface:
 * - it answers true only for a hash in its own format whose password
 *   matches, and false for anything else — every other unreadable hash
 *   reaches it too, including the crypt-family ones (`$2a$`, `$6$`) a wrong
 *   password did not open;
 * - it bounds the work a stored row can ask for (iterations, lengths) and
 *   parses strictly: a hostile row must not pin a worker;
 * - it compares in constant time (`hash_equals()`);
 * - it answers false, never throws, on a malformed row. A verifier that
 *   throws fails the verification — the throwable is reported through
 *   `Throwables::notify()` and no other verifier is asked for that hash;
 * - it marks the password and the stored hash `#[SensitiveParameter]`
 *   (attributes are not inherited from this contract), so neither appears
 *   in the trace of a reported throwable.
 */
interface Verifier
{
   /**
    * Verify a password against a stored hash of this verifier's format.
    *
    * @param string $password The password as typed.
    * @param string $hash The stored hash, never empty.
    *
    * @return bool True only when the hash is in this verifier's format and
    *    the password matches it.
    */
   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool;
}

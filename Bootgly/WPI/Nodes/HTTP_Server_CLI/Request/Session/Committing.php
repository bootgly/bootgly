<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


/**
 * Atomic persistence capability for handlers used by Request Session.
 *
 * Every successful fetch returns an opaque revision for that exact snapshot.
 * A later commit/revoke succeeds only while that revision still identifies the
 * live record. The comparison and mutation must share the backend's
 * synchronization boundary with destroy(), expiry, and purge operations.
 */
interface Committing extends Handling
{
   /**
    * Read one authenticated snapshot and expose its opaque storage revision.
    */
   public function fetch (
      string $sessionId,
      null|string &$revision = null,
   ): string|false;

   /**
    * Create when $revision is null, otherwise replace that exact revision.
    * On success $revision is updated to the newly persisted revision.
    */
   public function commit (
      string $sessionId,
      string $sessionData,
      null|string &$revision,
   ): bool;

   /** Atomically revoke only the exact fetched Session snapshot. */
   public function revoke (string $sessionId, string $revision): bool;
}

<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;


/**
 * Request limits of an HTTP Server.
 *
 * Handed to `HTTP_Server_CLI->configure()`; every limit left `null` keeps
 * whatever is currently configured — these are process-global statics, not
 * per-instance state, so `null` never resets one to the framework default.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs(1048576)` is rejected by the engine (and flagged
 * by static analysis) before it can silently bind the wrong limit.
 */
class Configs implements Configuring
{
   // * Config
   /** Maximum bytes of a single uploaded file. */
   public private(set) null|int $maxFileSize;
   /** Maximum bytes of a request body. */
   public private(set) null|int $maxBodySize;
   /** Maximum bytes of one multipart field value. */
   public private(set) null|int $maxMultipartFieldSize;
   /** Maximum bytes of one multipart part header block. */
   public private(set) null|int $maxMultipartHeaderSize;
   /** Maximum number of multipart fields. */
   public private(set) null|int $maxMultipartFields;
   /** Maximum number of multipart files. */
   public private(set) null|int $maxMultipartFiles;
   /** Maximum bytes every spooled download may keep on disk. */
   public private(set) null|int $downloadsMaxBytesOnDisk;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|int $maxFileSize = null,
      null|int $maxBodySize = null,
      null|int $maxMultipartFieldSize = null,
      null|int $maxMultipartHeaderSize = null,
      null|int $maxMultipartFields = null,
      null|int $maxMultipartFiles = null,
      null|int $downloadsMaxBytesOnDisk = null
   )
   {
      // * Config
      $this->maxFileSize = $maxFileSize;
      $this->maxBodySize = $maxBodySize;

      $this->maxMultipartFieldSize = $maxMultipartFieldSize;
      $this->maxMultipartHeaderSize = $maxMultipartHeaderSize;
      $this->maxMultipartFields = $maxMultipartFields;
      $this->maxMultipartFiles = $maxMultipartFiles;

      $this->downloadsMaxBytesOnDisk = $downloadsMaxBytesOnDisk;
   }
}

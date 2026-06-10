<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;


/**
 * RESP (REdis Serialization Protocol) client codec.
 *
 * Pure byte transforms, no I/O — shared by the blocking Redis cache driver
 * (ABI/Resources/Cache/Drivers/Redis) and the async KV Redis driver
 * (ADI/Databases/KV/Drivers/Redis). The Encoder is stateless; the Decoder
 * holds a per-connection buffer, so one RESP instance belongs to one
 * connection.
 */
class RESP
{
   // * Data
   public Encoder $Encoder;
   public Decoder $Decoder;


   public function __construct ()
   {
      // * Data
      $this->Encoder = new Encoder;
      $this->Decoder = new Decoder;
   }
}

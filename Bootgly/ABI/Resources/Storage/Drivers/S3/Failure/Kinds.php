<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;


/**
 * What went wrong with an S3 call, in the terms a caller branches on.
 */
enum Kinds
{
   /**
    * No complete answer arrived: the connection failed or dropped, the wait timed out, the body
    * was cut short, or the sink refused it. The request may be retried.
    */
   case Transport;
   /**
    * An answer arrived that is not the S3 answer to the request: malformed XML, no upload id or
    * ETag, another byte range than the one asked for, an answer too large to be one.
    */
   case Protocol;
   /**
    * The store said no — a status outside the operation's success set, or an `<Error>` inside a
    * `200` — or the driver refused the input before sending anything (status 0). The status and
    * the code tell whether trying again can help (`503 SlowDown` can, `403 AccessDenied` cannot).
    */
   case Refusal;
}

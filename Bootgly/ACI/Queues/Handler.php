<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


/**
 * Contract for a job handler.
 *
 * A handler is referenced by class-string on the Job (so the job stays
 * serializable across processes) and instantiated by the Worker at run time.
 * The closest existing analog is `ABI\Events\Emitter\Listener`.
 */
interface Handler
{
   /**
    * Execute the work carried by a job.
    *
    * @param Job $Job The job to handle.
    */
   public function handle (Job $Job): void;
}

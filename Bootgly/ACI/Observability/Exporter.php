<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability;


use Bootgly\ACI\Observability\Data\Snapshot;


interface Exporter
{
   /**
    * Encode a metrics snapshot into a transportable representation.
    *
    * @param Snapshot $Snapshot The snapshot to encode.
    * @return string The encoded bytes.
    */
   public function export (Snapshot $Snapshot): string;
}

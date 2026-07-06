<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


// ...Same-level entity required by the `Exceptions/` dependency subdirectory.
// The concrete exceptions inside `Exceptions/` cannot implement it (a
// subdirectory entity never depends on its same-name sibling) — catch
// `Exceptioning`, the Mail catch-all marker, instead.
interface Exceptions extends Exceptioning
{
}

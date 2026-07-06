<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * Client decoder contract. A decoder consumes bytes for one connection phase
 * (the handshake response, then the frame stream) and mutates the `Session`.
 *
 * The returned array tells the node how to proceed; `null` means the bytes are
 * incomplete and the node should buffer them on `Session->carry` and wait. Keys:
 *  - `consumed`    (int)     wire bytes this call consumed.
 *  - `established` (true)    the 101 was verified — switch to the frame decoder.
 *  - `message`     (Message) a complete message to surface.
 *  - `stop`        (true)    a close was received/sent — tear the connection down.
 *  - `fail`        (string)  the handshake response was invalid — drop the TCP connection.
 */
abstract class Decoders
{
   /**
    * @return null|array<string, mixed>
    */
   abstract public function decode (Session $Session, string $buffer): null|array;
}

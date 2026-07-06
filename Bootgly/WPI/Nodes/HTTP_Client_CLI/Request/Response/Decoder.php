<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


use Bootgly\WPI\Endpoints\Clients\Decoder as DecoderInterface;


abstract class Decoder implements DecoderInterface
{
   /**
    * @return null|array{protocol: string, code: int, status: string, headerRaw: string, bodyRaw: string, bodyLength: int, bodyDownloaded: int, bodyWaiting: bool, chunked: bool, closeConnection: bool, interim: bool, consumed: int}|array{complete: true, body: string, bodyLength: int, consumed: int, leftover: string}
    */
   abstract public function decode (string $buffer, int $size, null|string $method = null): null|array;
}

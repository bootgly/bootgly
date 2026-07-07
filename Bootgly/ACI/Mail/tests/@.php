<?php

namespace Bootgly\ACI\Mail;

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      '1.1-config',
      '2.1-decoder-replies',
      '2.2-decoder-fragments',
      '2.3-decoder-malformed',
      '3.1-encoder-commands',
      '3.2-encoder-stuffing',
      '4.1-extensions',
      '5.1-mechanisms',
      '6.1-values',
      '7.1-message-address',
      '7.2-message-encoder-words',
      '7.3-message-encoder-bodies',
      '7.4-message-attachment',
      '8.1-message-headers',
      '8.2-message-alternative',
      '8.3-message-tree',
      '8.4-message-render',
      '8.5-message-union',
      '9.1-message-template',
      '9.2-message-transfer',
   ]
);

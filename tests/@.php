<?php

use Bootgly\ACI\Tests\Suites;

return new Suites(
   directories: [
      // ! Abstract Bootable Interface (ABI)
      // ? 1
      #'Bootgly/ABI/Configs/*', // ! Not testable (for now)
      // ? 2
      0 => 'Bootgly/ABI/Data/__Array/',
      1 => 'Bootgly/ABI/Data/__String/',
      2 => 'Bootgly/ABI/Data/__String/Bytes/',
      #'Bootgly/ABI/Data/__String/Escapeable/', // ! Not testable directly (traits)
      3 => 'Bootgly/ABI/Data/__String/Path/',
      // ? 3
      #'Bootgly/ABI/Debugging/*', // ! Not testable
      // ? 4
      4 => 'Bootgly/ABI/IO/FS/Dir/',
      5 => 'Bootgly/ABI/IO/FS/File/',
      6 => 'Bootgly/ABI/IO/IPC/Pipe/', // ! Testable only individually
      // ? 5
      #'Bootgly/ABI/Resources/*', // ! Not testable (for now)
      // ? 6
      #'Bootgly/ABI/Templates/Directives/', // ! Not testable directly (part of Template)
      #'Bootgly/ABI/Templates/Iterator/', // ! Not testable (for now)
      #'Bootgly/ABI/Templates/Iterators/', // ! Not testable (for now)
      7 => 'Bootgly/ABI/Templates/Template/',
      8 => 'Bootgly/ABI/Templates/Template/Escaped/',

      // ! Abstract Common Interface (ACI)
      9 => 'Bootgly/ACI/Tests/',

      // ! Abstract Data Interface (ADI)
      10 => 'Bootgly/ADI/Table/',

      // ! Application Programming Interface (API)
      11 => 'Bootgly/API/Server/',

      // ! Command Line Interface (CLI)
      12 => 'Bootgly/CLI/Commands/',

      // ! Web Programming Interface (WPI)
      13 => 'Bootgly/WPI/Connections/tests/',
      // # HTTP_Client_CLI
      // Atomic
      14 => 'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/Atomic/',
      // E2E (use Bootgly's TCP_Server_CLI)
      15 => 'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E/',
      // E2E SSL (use Bootgly's TCP_Server_CLI with SSL)
      16 => 'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/',
      // # HTTP_Server_CLI
      17 => 'Bootgly/WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/',
      // E2E (use Bootgly's TCP_Client_CLI)
      18 => 'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/E2E/',
      // Security
      19 => 'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Security/',
   ]
);

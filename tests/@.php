<?php

use Bootgly\ACI\Tests\Suites;

return new Suites(
   directories: [
      // ! Abstract Bootable Interface (ABI)
      // ? 1
      #'Bootgly/ABI/Configs/*', // ! Not testable (for now)
      // ? 2
      'Bootgly/ABI/Data/__Array/',
      'Bootgly/ABI/Data/__String/',
      'Bootgly/ABI/Data/__String/Bytes/',
      #'Bootgly/ABI/Data/__String/Escapeable/', // ! Not testable directly (traits)
      'Bootgly/ABI/Data/__String/Path/',
      // ? 3
      #'Bootgly/ABI/Debugging/*', // ! Not testable
      // ? 3.5
      'Bootgly/ABI/Differ/',
      // ? 4
      'Bootgly/ABI/IO/FS/Dir/',
      'Bootgly/ABI/IO/FS/File/',
      'Bootgly/ABI/IO/IPC/Pipe/', // ! Testable only individually
      // ? 5
      #'Bootgly/ABI/Resources/*', // ! Not testable (for now)
      // ? 6
      #'Bootgly/ABI/Templates/Directives/', // ! Not testable directly (part of Template)
      #'Bootgly/ABI/Templates/Iterator/', // ! Not testable (for now)
      #'Bootgly/ABI/Templates/Iterators/', // ! Not testable (for now)
      'Bootgly/ABI/Templates/Template/',
      'Bootgly/ABI/Templates/Template/Escaped/',

      // ! Abstract Common Interface (ACI)
      'Bootgly/ACI/Tests/',

      // ! Abstract Data Interface (ADI)
      'Bootgly/ADI/Database/',
      'Bootgly/ADI/Databases/SQL/Builder/',
      'Bootgly/ADI/Databases/SQL/Schema/',
      'Bootgly/ADI/Table/',

      // ! Application Programming Interface (API)
      'Bootgly/API/Environment/Configs/',
      'Bootgly/API/Security/',
      'Bootgly/API/Workables/Server/',

      // ! Command Line Interface (CLI)
      'Bootgly/CLI/Commands/',

      // ! Web Programming Interface (WPI)
      'Bootgly/WPI/Connections/tests/',
      // # HTTP_Client_CLI
      // Atomic
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/Atomic/',
      // E2E (use Bootgly's TCP_Server_CLI)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E/',
      // E2E SSL (use Bootgly's TCP_Server_CLI with SSL)
      'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/',
      // # HTTP_Server_CLI
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Router/Middlewares/',
      // E2E (use Bootgly's TCP_Client_CLI)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/E2E/',
      // Request validation
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Request/Validation/',
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/Request/Validators/',
      // Security
      #'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Security/',
      // Fuzz (property-based / structure-aware fuzzing)
      #'Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/Fuzz/',
   ]
);

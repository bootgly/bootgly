<?php
return [
   'suites' => [
      // Abstract Bootable Interface (ABI)
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

      // Abstract Common Interface (ACI)
      # ...

      // Abstract Data Interface (ADI)
      'Bootgly/ADI/Table/',

      // Application Programming Interface (API)
      'Bootgly/API/',

      // Command Line Interface (CLI)
      'Bootgly/CLI/',

      // Web Programming Interface (WPI)
      'Bootgly/WPI/Nodes/HTTP_Server_CLI/',
   ]
];

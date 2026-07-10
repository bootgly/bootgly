<?php

// Mixed-value fixture — non-string values must be dropped, string values kept.
return [
   'Key A' => ['not', 'a', 'string'],
   'Key B' => 'Valor B',
   'Key C' => 42,
];

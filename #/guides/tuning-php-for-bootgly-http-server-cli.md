# Tuning PHP for Bootgly HTTP Server (CLI) - Guide

## php.ini Configuration:

- variables_order = ""
- auto_globals_jit = On
- mysqlnd.collect_statistics = Off

## PHP extensions:

- Disable XDebug
- Enable Opcache with JIT:

```ini
[opcache]

opcache.enable = 1
opcache.enable_cli = 1

opcache.memory_consumption = 128

opcache.interned_strings_buffer = 8

opcache.max_accelerated_files = 4000

opcache.validate_timestamps = 0

opcache.revalidate_freq = 2

opcache.save_comments = 0

opcache.enable_file_override = 1

opcache.huge_code_pages = 1

; --- JIT ---
opcache.jit = 1255

opcache.jit_bisect_limit = 0

opcache.jit_blacklist_root_trace = 16
opcache.jit_blacklist_side_trace = 8

opcache.jit_buffer_size = 128M

opcache.jit_debug = 0

opcache.jit_hot_func = 1
opcache.jit_hot_loop = 1
opcache.jit_hot_return = 1
opcache.jit_hot_side_exit = 1

opcache.jit_max_exit_counters = 102400 ; 1-9 | default = 8192
opcache.jit_max_loop_unrolls = 8 ; 1-9 | default = 8
opcache.jit_max_polymorphic_calls = 2
opcache.jit_max_recursive_calls = 2 ; 1-9 | default = 2
opcache.jit_max_recursive_returns = 2
opcache.jit_max_root_traces = 1024
opcache.jit_max_side_traces = 128

opcache.jit_prof_threshold = 0.005
```

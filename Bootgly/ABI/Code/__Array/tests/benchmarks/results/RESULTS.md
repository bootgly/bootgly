# Microbenchmark results

Generated from `results/*.json`. One file per case per PHP version, so this
page doubles as the version history.

> **A starting point, not an answer.** These ratios come from one machine under
> one workload. Re-run the case on your target runtime before relying on it.

🏆 marks the fastest measurement of each comparison — scan for it to see
which mechanism wins a scenario without reading the numbers.

## What to use

| Case | PHP | Inputs | Comparison | **Use this** | Fastest measured | Gain | Stable |
|---|---|---|---|---|---|---|---|
| `00-boundary` | 8.4.23 | `size=20` | map of 20 entries | **native array_key_last + index — reach for ->Last only for readability, outside hot paths** | 🏆 native, value only | 69% faster | **NO** (1.35x) |
| `01-shape` | 8.4.23 | `size=20` | ->multidimensional | **__Array ->multidimensional when the intent matters; the inline foreach in hot paths** | 🏆 inline foreach (no native equivalent) | baseline is fastest | yes |
| `02-search` | 8.4.23 | `size=40, hit=30` | list of 40, hit at 30 | **native array_search for a key; __Array::search for a needle list or the full triple** | 🏆 native array_search (hit) | baseline is fastest | yes |
| `03-wrapper-forms` | 8.4.23 | `size=50` | HEAVY operation — array_keys() over 50 entries | **native array_keys($a); if a wrapper is unavoidable, a static method — never magic __get** | 🏆 native array_keys($a) | baseline is fastest | yes |
| `03-wrapper-forms` | 8.4.23 | `size=50` | CHEAP operation — the {key, value} boundary pair | **native array_key_last + index — do not wrap cheap operations** | 🏆 native array_key_last + index | baseline is fastest | yes |
| `04-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 5 | **Pipeline built once + ->apply(); a per-call Pipeline barely breaks even this small** | 🏆 hand-fused loop (0 intermediates) | 72% faster | yes |
| `04-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 20 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 72% faster | yes |
| `04-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 100 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 70% faster | yes |
| `04-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 1000 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 71% faster | yes |
| `05-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 5 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 67% faster | yes |
| `05-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 20 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 70% faster | yes |
| `05-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 100 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 70% faster | yes |
| `06-array-interfaces` | 8.4.23 | `size=100` | iterate + sum | **native foreach — iterating an object is never cheaper than iterating the array it holds** | 🏆 native foreach | baseline is fastest | yes |
| `06-array-interfaces` | 8.4.23 | `size=100` | random access $a[$k] | **native $array[$key]; if the array is behind an object, index the public property directly** | 🏆 native $array[$key] | baseline is fastest | yes |
| `06-array-interfaces` | 8.4.23 | `size=100` | count | **native count($array) — Countable only relays the same call** | 🏆 native count($array) | baseline is fastest | yes |
| `07-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 5 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 70% faster | yes |
| `07-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 20 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 71% faster | yes |
| `07-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 100 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 70% faster | yes |
| `07-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 1000 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 71% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at 5% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 98% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at 5% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 94% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at 5% | **native array_find — with one filter and a hit near the front, C wins** | 🏆 native array_find (PHP 8.4, C) | baseline is fastest | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at 50% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 86% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at 50% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 81% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at 50% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 47% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at miss | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 73% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at miss | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 66% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at miss | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 50% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at 5% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 99% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at 5% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 98% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at 5% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 47% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at 50% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 86% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at 50% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 85% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at 50% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 54% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at miss | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 72% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at miss | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 70% faster | yes |
| `08-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at miss | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 54% faster | yes |
| `09-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 5 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 70% faster | yes |
| `09-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 8 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 71% faster | yes |
| `09-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 20 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 71% faster | yes |
| `09-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 100 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 70% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 20 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 54% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 20 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 hand-fused fold | 73% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 100 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 68% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 100 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 hand-fused fold | 72% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 1000 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 72% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 1000 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 Pipeline ->reduce() | 74% faster | yes |

## Full measurements

### `00-boundary`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:15:53+00:00 · best-of-5 x 200,000 iterations, floor 10.9 ns

`inputs: size=20`

*map of 20 entries*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_key_last + index | 40.2 | 1.00x |
| 🏆 **native, value only** | 12.4 | 0.31x |
| __Array ->Last (instance reused) | 123.6 | 3.07x |
| __Array ->Last (constructed per call) | 140.5 | 3.50x |
| __Array ->First (instance reused) | 116.6 | 2.90x |

**Use:** native array_key_last + index — reach for ->Last only for readability, outside hot paths

> The wrapper cannot beat the call it hides — its floor is that call plus the dispatch. ->Last earns its cost only where the {key, value} pair genuinely simplifies the caller; constructing one per call never pays.

### `01-shape`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:11:08+00:00 · best-of-5 x 200,000 iterations, floor 11.0 ns

`inputs: size=20`

*->multidimensional*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **inline foreach (no native equivalent)** | 45.1 | 1.00x |
| __Array ->multidimensional (reused) | 76.7 | 1.70x |
| __Array ->multidimensional (per call) | 122.7 | 2.72x |

**Use:** __Array ->multidimensional when the intent matters; the inline foreach in hot paths

> The closest call in the class: the work is a loop, so the dispatch is diluted rather than dominant. This is where __Array reads best — it names an intent PHP has no single call for.

### `02-search`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:11:14+00:00 · best-of-5 x 200,000 iterations, floor 11.0 ns

`inputs: size=40, hit=30`

*list of 40, hit at 30*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_search (hit)** | 113.4 | 1.00x |
| native + build the pair (hit) | 154.2 | 1.36x |
| __Array::search (hit) | 235.0 | 2.07x |
| __Array::search (miss) | 243.5 | 2.15x |
| __Array::search (needle list) | 406.7 | 3.59x |

**Use:** native array_search for a key; __Array::search for a needle list or the full triple

> Native search is the floor. __Array::search earns its cost only when you want the {key, value, found} triple without writing it out, or when trying several needles in order — which native search cannot express at all.

### `03-wrapper-forms`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:11:23+00:00 · best-of-5 x 200,000 iterations, floor 11.4 ns

`inputs: size=50`

*HEAVY operation — array_keys() over 50 entries*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_keys($a)** | 138.9 | 1.00x |
| static method | 149.8 | 1.08x |
| property hook (instance reused) | 201.3 | 1.45x |
| magic __get (instance reused) | 208.5 | 1.50x |
| property hook + construction | 258.9 | 1.86x |
| magic __get + construction | 274.5 | 1.98x |

**Use:** native array_keys($a); if a wrapper is unavoidable, a static method — never magic __get

> Real work dilutes the overhead — the cheapest wrapper form (a static method) lands within ~10%, while magic __get roughly doubles the cost.

*CHEAP operation — the {key, value} boundary pair*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_key_last + index** | 49.7 | 1.00x |
| __Array ->Last (instance reused) | 120.0 | 2.41x |
| __Array ->Last + construction | 173.5 | 3.49x |

**Use:** native array_key_last + index — do not wrap cheap operations

> Same absolute overhead, far less work to hide it, so the ratio blows up. Framework arrays (headers, route params, query args) are all cheap operations, which is why routing them through a wrapper is the wrong trade.

### `04-chain-fusion`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:11:45+00:00 · best-of-5 x 200,000 iterations, floor 11.2 ns

`inputs: sizes=5,20,100,1000`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 439.0 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 120.9 | 0.28x |
| Pipeline (constructed per call) | 401.8 | 0.92x |
| Pipeline (built once, ->apply()) | 163.8 | 0.37x |

**Use:** Pipeline built once + ->apply(); a per-call Pipeline barely breaks even this small

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 1398.1 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 390.4 | 0.28x |
| Pipeline (constructed per call) | 686.4 | 0.49x |
| Pipeline (built once, ->apply()) | 445.1 | 0.32x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 6247.6 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 1871.4 | 0.30x |
| Pipeline (constructed per call) | 2247.9 | 0.36x |
| Pipeline (built once, ->apply()) | 2000.4 | 0.32x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 61904.0 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 17828.0 | 0.29x |
| Pipeline (constructed per call) | 18929.4 | 0.31x |
| Pipeline (built once, ->apply()) | 18629.6 | 0.30x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

### `05-native-classes`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:12:14+00:00 · best-of-5 x 200,000 iterations, floor 11.0 ns

`inputs: sizes=5,20,100`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 439.9 | 1.00x |
| generator pipeline (C coroutine) | 414.8 | 0.94x |
| SPL CallbackFilterIterator | 1313.7 | 2.99x |
| SplFixedArray fused | 472.4 | 1.07x |
| 🏆 **plain fused foreach** | 143.6 | 0.33x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 1382.6 | 1.00x |
| generator pipeline (C coroutine) | 806.9 | 0.58x |
| SPL CallbackFilterIterator | 3507.8 | 2.54x |
| SplFixedArray fused | 1050.8 | 0.76x |
| 🏆 **plain fused foreach** | 417.3 | 0.30x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 6354.0 | 1.00x |
| generator pipeline (C coroutine) | 2857.9 | 0.45x |
| SPL CallbackFilterIterator | 15317.2 | 2.41x |
| SplFixedArray fused | 4083.5 | 0.64x |
| 🏆 **plain fused foreach** | 1919.4 | 0.30x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

### `06-array-interfaces`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:12:24+00:00 · best-of-5 x 200,000 iterations, floor 11.1 ns

`inputs: size=100`

*iterate + sum*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native foreach** | 248.0 | 1.00x |
| IteratorAggregate (yield from) | 1833.9 | 7.39x |
| Iterator (hand-rolled cursor) | 9316.5 | 37.57x |
| ArrayObject (built-in) | 2978.1 | 12.01x |
| SplFixedArray (built-in) | 1248.9 | 5.04x |

**Use:** native foreach — iterating an object is never cheaper than iterating the array it holds

> Every object shape pays dispatch per element that a native foreach does not. yield from is the cheapest of them, which makes IteratorAggregate the right choice IF the interface is wanted for ergonomics — never for speed.

*random access $a[$k]*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native $array[$key]** | 6.2 | 1.00x |
| ArrayAccess (userland) | 44.3 | 7.15x |
| ArrayObject (built-in) | 23.7 | 3.82x |
| public property + index | 6.7 | 1.08x |

**Use:** native $array[$key]; if the array is behind an object, index the public property directly

> ArrayAccess routes a native opcode through a method call. Exposing the array as a public property and indexing it stays far closer to native than implementing the interface does.

*count*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native count($array)** | 3.5 | 1.00x |
| Countable (userland) | 34.4 | 9.83x |
| ArrayObject (built-in) | 10.5 | 3.00x |

**Use:** native count($array) — Countable only relays the same call

> count() on a Countable dispatches into userland to run the very count() it was asked to replace.

### `07-pipeline-shapes`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:12:49+00:00 · best-of-5 x 200,000 iterations, floor 11.1 ns

`inputs: sizes=5,20,100,1000`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 439.4 | 1.00x |
| Generic (op-loop per element) | 434.2 | 0.99x |
| Pipeline (shape-dispatched) | 393.9 | 0.90x |
| 🏆 **hand-fused loop** | 131.2 | 0.30x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 1382.9 | 1.00x |
| Generic (op-loop per element) | 949.6 | 0.69x |
| Pipeline (shape-dispatched) | 683.7 | 0.49x |
| 🏆 **hand-fused loop** | 406.7 | 0.29x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 6335.3 | 1.00x |
| Generic (op-loop per element) | 3624.8 | 0.57x |
| Pipeline (shape-dispatched) | 2199.5 | 0.35x |
| 🏆 **hand-fused loop** | 1896.2 | 0.30x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 60693.5 | 1.00x |
| Generic (op-loop per element) | 32980.1 | 0.54x |
| Pipeline (shape-dispatched) | 18677.7 | 0.31x |
| 🏆 **hand-fused loop** | 17835.9 | 0.29x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

### `08-early-exit`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:51:44+00:00 · best-of-5 x 200,000 iterations, floor 11.1 ns

`inputs: sizes=100,1000`

*chain -> first match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5837.2 | 1.00x |
| native array_find(array_map()) | 2971.9 | 0.51x |
| Pipeline ->map->filter->find() | 370.0 | 0.06x |
| 🏆 **hand foreach + return** | 107.5 | 0.02x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5849.0 | 1.00x |
| native array_any(array_map()) | 2984.4 | 0.51x |
| 🏆 **Pipeline ->map->filter->check()** | 366.4 | 0.06x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_find (PHP 8.4, C)** | 267.1 | 1.00x |
| Pipeline ->filter->find() | 282.5 | 1.06x |

**Use:** native array_find — with one filter and a hit near the front, C wins

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5906.4 | 1.00x |
| native array_find(array_map()) | 4661.9 | 0.79x |
| Pipeline ->map->filter->find() | 1132.6 | 0.19x |
| 🏆 **hand foreach + return** | 833.1 | 0.14x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5843.5 | 1.00x |
| native array_any(array_map()) | 4664.4 | 0.80x |
| 🏆 **Pipeline ->map->filter->check()** | 1137.9 | 0.19x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 1960.8 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1044.2 | 0.53x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5872.5 | 1.00x |
| native array_find(array_map()) | 6459.5 | 1.10x |
| Pipeline ->map->filter->find() | 1964.1 | 0.33x |
| 🏆 **hand foreach + return** | 1610.7 | 0.27x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5838.4 | 1.00x |
| native array_any(array_map()) | 6497.6 | 1.11x |
| 🏆 **Pipeline ->map->filter->check()** | 1963.4 | 0.34x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 3754.9 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1872.7 | 0.50x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 56896.7 | 1.00x |
| native array_find(array_map()) | 28115.3 | 0.49x |
| Pipeline ->map->filter->find() | 1126.4 | 0.02x |
| 🏆 **hand foreach + return** | 829.3 | 0.01x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 57802.5 | 1.00x |
| native array_any(array_map()) | 28490.2 | 0.49x |
| 🏆 **Pipeline ->map->filter->check()** | 1128.4 | 0.02x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 1938.4 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1030.4 | 0.53x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 57017.4 | 1.00x |
| native array_find(array_map()) | 45398.9 | 0.80x |
| Pipeline ->map->filter->find() | 8680.2 | 0.15x |
| 🏆 **hand foreach + return** | 7942.0 | 0.14x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 57747.2 | 1.00x |
| native array_any(array_map()) | 45374.2 | 0.79x |
| 🏆 **Pipeline ->map->filter->check()** | 8761.6 | 0.15x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 18941.0 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 8677.8 | 0.46x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 57750.8 | 1.00x |
| native array_find(array_map()) | 63878.5 | 1.11x |
| Pipeline ->map->filter->find() | 17108.8 | 0.30x |
| 🏆 **hand foreach + return** | 16005.0 | 0.28x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 57054.4 | 1.00x |
| native array_any(array_map()) | 63192.9 | 1.11x |
| 🏆 **Pipeline ->map->filter->check()** | 17162.9 | 0.30x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 37289.0 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 17089.5 | 0.46x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

### `09-pipeline-reuse`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:14:54+00:00 · best-of-5 x 200,000 iterations, floor 11.1 ns

`inputs: sizes=5,8,20,100`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 438.6 | 1.00x |
| Pipeline (constructed per call) | 382.6 | 0.87x |
| Pipeline (built once, ->apply()) | 152.2 | 0.35x |
| 🏆 **hand-fused loop** | 132.5 | 0.30x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 8*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 628.8 | 1.00x |
| Pipeline (constructed per call) | 441.2 | 0.70x |
| Pipeline (built once, ->apply()) | 205.8 | 0.33x |
| 🏆 **hand-fused loop** | 184.2 | 0.29x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 1377.2 | 1.00x |
| Pipeline (constructed per call) | 667.8 | 0.48x |
| Pipeline (built once, ->apply()) | 434.2 | 0.32x |
| 🏆 **hand-fused loop** | 405.4 | 0.29x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 6333.0 | 1.00x |
| Pipeline (constructed per call) | 2186.6 | 0.35x |
| Pipeline (built once, ->apply()) | 1956.6 | 0.31x |
| 🏆 **hand-fused loop** | 1893.4 | 0.30x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

### `10-terminals`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T17:15:23+00:00 · best-of-5 x 200,000 iterations, floor 11.2 ns

`inputs: sizes=20,100,1000`

*count, n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 1352.3 | 1.00x |
| 🏆 **Pipeline ->count()** | 618.9 | 0.46x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 1538.4 | 1.00x |
| Pipeline ->reduce() | 653.2 | 0.42x |
| 🏆 **hand-fused fold** | 408.6 | 0.27x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

*count, n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 6181.8 | 1.00x |
| 🏆 **Pipeline ->count()** | 1979.3 | 0.32x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 7135.8 | 1.00x |
| Pipeline ->reduce() | 2098.4 | 0.29x |
| 🏆 **hand-fused fold** | 1968.2 | 0.28x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

*count, n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 61828.4 | 1.00x |
| 🏆 **Pipeline ->count()** | 17299.0 | 0.28x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 71011.9 | 1.00x |
| 🏆 **Pipeline ->reduce()** | 18427.8 | 0.26x |
| hand-fused fold | 19664.1 | 0.28x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

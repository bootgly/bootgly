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
| `0-boundary` | 8.4.23 | `size=20` | map of 20 entries | **native array_key_last + index — reach for ->Last only for readability, outside hot paths** | 🏆 native, value only | 72% faster | yes |
| `1-shape` | 8.4.23 | `size=20` | ->multidimensional | **__Array ->multidimensional when the intent matters; the inline foreach in hot paths** | 🏆 inline foreach (no native equivalent) | baseline is fastest | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 20 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 53% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 20 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 hand-fused fold | 74% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 100 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 68% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 100 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 hand-fused fold | 73% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | count, n = 1000 | **Pipeline ->count() — counting never needs the array it counts** | 🏆 Pipeline ->count() | 72% faster | yes |
| `10-terminals` | 8.4.23 | `sizes=20,100,1000` | reduce, n = 1000 | **Pipeline ->reduce() — it folds inside the pass instead of after it** | 🏆 hand-fused fold | 73% faster | yes |
| `2-search` | 8.4.23 | `size=40, hit=30` | list of 40, hit at 30 | **native array_search for a key; __Array::search for a needle list or the full triple** | 🏆 native array_search (hit) | baseline is fastest | yes |
| `3-wrapper-forms` | 8.4.23 | `size=50` | HEAVY operation — array_keys() over 50 entries | **native array_keys($a); if a wrapper is unavoidable, a static method — never magic __get** | 🏆 native array_keys($a) | baseline is fastest | yes |
| `3-wrapper-forms` | 8.4.23 | `size=50` | CHEAP operation — the {key, value} boundary pair | **native array_key_last + index — do not wrap cheap operations** | 🏆 native array_key_last + index | baseline is fastest | yes |
| `4-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 5 | **Pipeline built once + ->apply(); a per-call Pipeline barely breaks even this small** | 🏆 hand-fused loop (0 intermediates) | 69% faster | yes |
| `4-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 20 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 71% faster | yes |
| `4-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 100 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 71% faster | yes |
| `4-chain-fusion` | 8.4.23 | `sizes=5,20,100,1000` | n = 1000 | **Pipeline — it ties the hand-written loop and reads as the chain it replaces** | 🏆 hand-fused loop (0 intermediates) | 70% faster | yes |
| `5-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 5 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 69% faster | yes |
| `5-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 20 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 70% faster | yes |
| `5-native-classes` | 8.4.23 | `sizes=5,20,100` | n = 100 | **plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily** | 🏆 plain fused foreach | 70% faster | yes |
| `6-array-interfaces` | 8.4.23 | `size=100` | iterate + sum | **native foreach — iterating an object is never cheaper than iterating the array it holds** | 🏆 native foreach | baseline is fastest | yes |
| `6-array-interfaces` | 8.4.23 | `size=100` | random access $a[$k] | **native $array[$key]; if the array is behind an object, index the public property directly** | 🏆 native $array[$key] | baseline is fastest | yes |
| `6-array-interfaces` | 8.4.23 | `size=100` | count | **native count($array) — Countable only relays the same call** | 🏆 native count($array) | baseline is fastest | yes |
| `7-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 5 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 71% faster | yes |
| `7-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 20 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 70% faster | yes |
| `7-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 100 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 hand-fused loop | 69% faster | yes |
| `7-pipeline-shapes` | 8.4.23 | `sizes=5,20,100,1000` | n = 1000 | **Pipeline — the shipped shape dispatch; Generic is the prototype it replaced** | 🏆 Pipeline (shape-dispatched) | 70% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at 5% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 98% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at 5% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 94% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at 5% | **native array_find — with one filter and a hit near the front, C wins** | 🏆 native array_find (PHP 8.4, C) | baseline is fastest | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at 50% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 86% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at 50% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 81% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at 50% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 46% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 100, hit at miss | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 73% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 100, hit at miss | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 67% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 100, hit at miss | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 49% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at 5% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 99% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at 5% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 98% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at 5% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 47% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at 50% | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 86% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at 50% | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 85% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at 50% | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 54% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> first match, n = 1000, hit at miss | **Pipeline ->find() — it ties the hand-written loop and beats every native form** | 🏆 hand foreach + return | 73% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | chain -> any match, n = 1000, hit at miss | **Pipeline ->check() — never materialize an array to ask whether it would be empty** | 🏆 Pipeline ->map->filter->check() | 70% faster | yes |
| `8-early-exit` | 8.4.23 | `sizes=100,1000` | single filter -> first match, n = 1000, hit at miss | **Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does** | 🏆 Pipeline ->filter->find() | 54% faster | yes |
| `9-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 5 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 69% faster | yes |
| `9-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 8 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 70% faster | yes |
| `9-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 20 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 71% faster | yes |
| `9-pipeline-reuse` | 8.4.23 | `sizes=5,8,20,100` | n = 100 | **Pipeline built once + ->apply() — the only form that wins at hot-path sizes** | 🏆 hand-fused loop | 70% faster | yes |

## Full measurements

### `0-boundary`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:31:31+00:00 · best-of-5 x 200,000 iterations, floor 11.5 ns

`inputs: size=20`

*map of 20 entries*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_key_last + index | 43.9 | 1.00x |
| 🏆 **native, value only** | 12.4 | 0.28x |
| __Array ->Last (instance reused) | 123.7 | 2.82x |
| __Array ->Last (constructed per call) | 147.7 | 3.36x |
| __Array ->First (instance reused) | 122.3 | 2.79x |

**Use:** native array_key_last + index — reach for ->Last only for readability, outside hot paths

> The wrapper cannot beat the call it hides — its floor is that call plus the dispatch. ->Last earns its cost only where the {key, value} pair genuinely simplifies the caller; constructing one per call never pays.

### `1-shape`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:31:33+00:00 · best-of-5 x 200,000 iterations, floor 11.4 ns

`inputs: size=20`

*->multidimensional*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **inline foreach (no native equivalent)** | 45.2 | 1.00x |
| __Array ->multidimensional (reused) | 78.6 | 1.74x |
| __Array ->multidimensional (per call) | 156.9 | 3.47x |

**Use:** __Array ->multidimensional when the intent matters; the inline foreach in hot paths

> The closest call in the class: the work is a loop, so the dispatch is diluted rather than dominant. This is where __Array reads best — it names an intent PHP has no single call for.

### `10-terminals`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:46:43+00:00 · best-of-5 x 200,000 iterations, floor 11.6 ns

`inputs: sizes=20,100,1000`

*count, n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 1331.0 | 1.00x |
| 🏆 **Pipeline ->count()** | 631.1 | 0.47x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 1563.2 | 1.00x |
| Pipeline ->reduce() | 690.1 | 0.44x |
| 🏆 **hand-fused fold** | 401.3 | 0.26x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

*count, n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 6186.4 | 1.00x |
| 🏆 **Pipeline ->count()** | 2006.0 | 0.32x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 7199.8 | 1.00x |
| Pipeline ->reduce() | 2268.2 | 0.32x |
| 🏆 **hand-fused fold** | 1944.8 | 0.27x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

*count, n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native count(filter(map)) | 62400.0 | 1.00x |
| 🏆 **Pipeline ->count()** | 17473.8 | 0.28x |

**Use:** Pipeline ->count() — counting never needs the array it counts

> Two arrays are materialized to produce one integer. Counting as the pass goes needs neither.

*reduce, n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_reduce(filter(map)) | 70909.8 | 1.00x |
| Pipeline ->reduce() | 20101.2 | 0.28x |
| 🏆 **hand-fused fold** | 19397.8 | 0.27x |

**Use:** Pipeline ->reduce() — it folds inside the pass instead of after it

> array_reduce() cannot fold what has not been built yet, so the whole filtered array exists before the fold starts.

### `2-search`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:31:39+00:00 · best-of-5 x 200,000 iterations, floor 11.3 ns

`inputs: size=40, hit=30`

*list of 40, hit at 30*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_search (hit)** | 115.7 | 1.00x |
| native + build the pair (hit) | 156.6 | 1.35x |
| __Array::search (hit) | 241.9 | 2.09x |
| __Array::search (miss) | 247.6 | 2.14x |
| __Array::search (needle list) | 410.5 | 3.55x |

**Use:** native array_search for a key; __Array::search for a needle list or the full triple

> Native search is the floor. __Array::search earns its cost only when you want the {key, value, found} triple without writing it out, or when trying several needles in order — which native search cannot express at all.

### `3-wrapper-forms`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:31:48+00:00 · best-of-5 x 200,000 iterations, floor 11.2 ns

`inputs: size=50`

*HEAVY operation — array_keys() over 50 entries*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_keys($a)** | 142.7 | 1.00x |
| static method | 152.1 | 1.07x |
| property hook (instance reused) | 201.9 | 1.41x |
| magic __get (instance reused) | 206.3 | 1.45x |
| property hook + construction | 260.9 | 1.83x |
| magic __get + construction | 270.2 | 1.89x |

**Use:** native array_keys($a); if a wrapper is unavoidable, a static method — never magic __get

> Real work dilutes the overhead — the cheapest wrapper form (a static method) lands within ~10%, while magic __get roughly doubles the cost.

*CHEAP operation — the {key, value} boundary pair*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_key_last + index** | 50.4 | 1.00x |
| __Array ->Last (instance reused) | 121.2 | 2.40x |
| __Array ->Last + construction | 146.0 | 2.90x |

**Use:** native array_key_last + index — do not wrap cheap operations

> Same absolute overhead, far less work to hide it, so the ratio blows up. Framework arrays (headers, route params, query args) are all cheap operations, which is why routing them through a wrapper is the wrong trade.

### `4-chain-fusion`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:32:11+00:00 · best-of-5 x 200,000 iterations, floor 10.7 ns

`inputs: sizes=5,20,100,1000`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 443.6 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 139.7 | 0.31x |
| Pipeline (constructed per call) | 396.6 | 0.89x |
| Pipeline (built once, ->apply()) | 164.3 | 0.37x |

**Use:** Pipeline built once + ->apply(); a per-call Pipeline barely breaks even this small

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 1435.6 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 411.0 | 0.29x |
| Pipeline (constructed per call) | 680.7 | 0.47x |
| Pipeline (built once, ->apply()) | 445.5 | 0.31x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 6507.6 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 1905.8 | 0.29x |
| Pipeline (constructed per call) | 2195.4 | 0.34x |
| Pipeline (built once, ->apply()) | 1966.0 | 0.30x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

*n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain (2 intermediates) | 62266.3 | 1.00x |
| 🏆 **hand-fused loop (0 intermediates)** | 18598.8 | 0.30x |
| Pipeline (constructed per call) | 19116.6 | 0.31x |
| Pipeline (built once, ->apply()) | 19048.9 | 0.31x |

**Use:** Pipeline — it ties the hand-written loop and reads as the chain it replaces

> The intermediates and the C-level callback dispatch are what the native chain pays for; one pass with a userland callback pays neither. Only hand-inlining the transform so no callable is invoked at all goes faster, and no API can express that.

### `5-native-classes`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:32:40+00:00 · best-of-5 x 200,000 iterations, floor 10.9 ns

`inputs: sizes=5,20,100`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 445.6 | 1.00x |
| generator pipeline (C coroutine) | 425.9 | 0.96x |
| SPL CallbackFilterIterator | 1324.3 | 2.97x |
| SplFixedArray fused | 469.7 | 1.05x |
| 🏆 **plain fused foreach** | 139.0 | 0.31x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 1397.1 | 1.00x |
| generator pipeline (C coroutine) | 807.6 | 0.58x |
| SPL CallbackFilterIterator | 3549.1 | 2.54x |
| SplFixedArray fused | 1045.0 | 0.75x |
| 🏆 **plain fused foreach** | 415.3 | 0.30x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| function chain (array_map+array_filter) | 6410.5 | 1.00x |
| generator pipeline (C coroutine) | 2878.0 | 0.45x |
| SPL CallbackFilterIterator | 15576.2 | 2.43x |
| SplFixedArray fused | 3985.9 | 0.62x |
| 🏆 **plain fused foreach** | 1925.8 | 0.30x |

**Use:** plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily

### `6-array-interfaces`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:32:50+00:00 · best-of-5 x 200,000 iterations, floor 11.3 ns

`inputs: size=100`

*iterate + sum*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native foreach** | 247.5 | 1.00x |
| IteratorAggregate (yield from) | 1845.2 | 7.46x |
| Iterator (hand-rolled cursor) | 9231.3 | 37.30x |
| ArrayObject (built-in) | 2987.0 | 12.07x |
| SplFixedArray (built-in) | 1261.5 | 5.10x |

**Use:** native foreach — iterating an object is never cheaper than iterating the array it holds

> Every object shape pays dispatch per element that a native foreach does not. yield from is the cheapest of them, which makes IteratorAggregate the right choice IF the interface is wanted for ergonomics — never for speed.

*random access $a[$k]*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native $array[$key]** | 6.1 | 1.00x |
| ArrayAccess (userland) | 46.2 | 7.57x |
| ArrayObject (built-in) | 24.9 | 4.08x |
| public property + index | 6.8 | 1.11x |

**Use:** native $array[$key]; if the array is behind an object, index the public property directly

> ArrayAccess routes a native opcode through a method call. Exposing the array as a public property and indexing it stays far closer to native than implementing the interface does.

*count*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native count($array)** | 3.5 | 1.00x |
| Countable (userland) | 35.0 | 10.00x |
| ArrayObject (built-in) | 11.9 | 3.40x |

**Use:** native count($array) — Countable only relays the same call

> count() on a Countable dispatches into userland to run the very count() it was asked to replace.

### `7-pipeline-shapes`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:33:16+00:00 · best-of-5 x 200,000 iterations, floor 11.1 ns

`inputs: sizes=5,20,100,1000`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 441.6 | 1.00x |
| Generic (op-loop per element) | 449.3 | 1.02x |
| Pipeline (shape-dispatched) | 388.7 | 0.88x |
| 🏆 **hand-fused loop** | 126.7 | 0.29x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 1399.0 | 1.00x |
| Generic (op-loop per element) | 961.2 | 0.69x |
| Pipeline (shape-dispatched) | 670.6 | 0.48x |
| 🏆 **hand-fused loop** | 414.5 | 0.30x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 6395.5 | 1.00x |
| Generic (op-loop per element) | 3644.6 | 0.57x |
| Pipeline (shape-dispatched) | 2204.2 | 0.34x |
| 🏆 **hand-fused loop** | 2000.5 | 0.31x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

*n = 1000*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 62215.8 | 1.00x |
| Generic (op-loop per element) | 33160.3 | 0.53x |
| 🏆 **Pipeline (shape-dispatched)** | 18654.1 | 0.30x |
| hand-fused loop | 19022.5 | 0.31x |

**Use:** Pipeline — the shipped shape dispatch; Generic is the prototype it replaced

> Dispatching once per chain rather than once per element is the entire margin. Nothing else about the two implementations differs.

### `8-early-exit`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:38:55+00:00 · best-of-5 x 200,000 iterations, floor 11.2 ns

`inputs: sizes=100,1000`

*chain -> first match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5875.5 | 1.00x |
| native array_find(array_map()) | 2977.3 | 0.51x |
| Pipeline ->map->filter->find() | 361.8 | 0.06x |
| 🏆 **hand foreach + return** | 107.7 | 0.02x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5907.5 | 1.00x |
| native array_any(array_map()) | 3026.7 | 0.51x |
| 🏆 **Pipeline ->map->filter->check()** | 361.4 | 0.06x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| 🏆 **native array_find (PHP 8.4, C)** | 267.5 | 1.00x |
| Pipeline ->filter->find() | 291.3 | 1.09x |

**Use:** native array_find — with one filter and a hit near the front, C wins

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5869.7 | 1.00x |
| native array_find(array_map()) | 4747.9 | 0.81x |
| Pipeline ->map->filter->find() | 1132.1 | 0.19x |
| 🏆 **hand foreach + return** | 836.8 | 0.14x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5847.5 | 1.00x |
| native array_any(array_map()) | 4676.1 | 0.80x |
| 🏆 **Pipeline ->map->filter->check()** | 1134.8 | 0.19x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 1956.8 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1051.0 | 0.54x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 5947.2 | 1.00x |
| native array_find(array_map()) | 6586.0 | 1.11x |
| Pipeline ->map->filter->find() | 1951.6 | 0.33x |
| 🏆 **hand foreach + return** | 1605.9 | 0.27x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 5869.5 | 1.00x |
| native array_any(array_map()) | 6476.1 | 1.10x |
| 🏆 **Pipeline ->map->filter->check()** | 1949.4 | 0.33x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 100, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 3712.4 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1877.4 | 0.51x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 56936.5 | 1.00x |
| native array_find(array_map()) | 28849.2 | 0.51x |
| Pipeline ->map->filter->find() | 1126.2 | 0.02x |
| 🏆 **hand foreach + return** | 826.7 | 0.01x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 56918.3 | 1.00x |
| native array_any(array_map()) | 29970.1 | 0.53x |
| 🏆 **Pipeline ->map->filter->check()** | 1127.1 | 0.02x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at 5%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 1953.2 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 1044.4 | 0.53x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 57278.4 | 1.00x |
| native array_find(array_map()) | 45547.1 | 0.80x |
| Pipeline ->map->filter->find() | 8679.2 | 0.15x |
| 🏆 **hand foreach + return** | 7963.0 | 0.14x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 57193.8 | 1.00x |
| native array_any(array_map()) | 46867.9 | 0.82x |
| 🏆 **Pipeline ->map->filter->check()** | 8702.7 | 0.15x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at 50%*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 18719.5 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 8658.8 | 0.46x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

*chain -> first match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain then [0] | 58309.8 | 1.00x |
| native array_find(array_map()) | 65541.9 | 1.12x |
| Pipeline ->map->filter->find() | 17101.7 | 0.29x |
| 🏆 **hand foreach + return** | 16000.1 | 0.27x |

**Use:** Pipeline ->find() — it ties the hand-written loop and beats every native form

> Materializing to answer "which one is first" is the expensive part. Even array_find() over a mapped array pays for the map in full.

*chain -> any match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_filter !== [] | 57031.7 | 1.00x |
| native array_any(array_map()) | 64843.6 | 1.14x |
| 🏆 **Pipeline ->map->filter->check()** | 17216.3 | 0.30x |

**Use:** Pipeline ->check() — never materialize an array to ask whether it would be empty

> Building a filtered array to test it against [] is the most expensive way to ask a yes/no question about an array.

*single filter -> first match, n = 1000, hit at miss*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native array_find (PHP 8.4, C) | 37011.3 | 1.00x |
| 🏆 **Pipeline ->filter->find()** | 17097.3 | 0.46x |

**Use:** Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does

> The only configuration the native call wins is a single filter whose hit is a handful of elements in; past that, per-element callback dispatch decides it, and userland wins that.

### `9-pipeline-reuse`

**PHP 8.4.23** — opcache on, JIT on, Linux · 2026-08-15T16:35:24+00:00 · best-of-5 x 200,000 iterations, floor 11.0 ns

`inputs: sizes=5,8,20,100`

*n = 5*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 435.6 | 1.00x |
| Pipeline (constructed per call) | 389.7 | 0.89x |
| Pipeline (built once, ->apply()) | 169.4 | 0.39x |
| 🏆 **hand-fused loop** | 135.1 | 0.31x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 8*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 624.9 | 1.00x |
| Pipeline (constructed per call) | 449.2 | 0.72x |
| Pipeline (built once, ->apply()) | 224.4 | 0.36x |
| 🏆 **hand-fused loop** | 187.4 | 0.30x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 20*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 1411.4 | 1.00x |
| Pipeline (constructed per call) | 669.0 | 0.47x |
| Pipeline (built once, ->apply()) | 443.6 | 0.31x |
| 🏆 **hand-fused loop** | 408.2 | 0.29x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

*n = 100*

| Measurement | ns/op | vs baseline |
|---|---:|---:|
| native chain | 6405.6 | 1.00x |
| Pipeline (constructed per call) | 2219.8 | 0.35x |
| Pipeline (built once, ->apply()) | 2009.7 | 0.31x |
| 🏆 **hand-fused loop** | 1940.5 | 0.30x |

**Use:** Pipeline built once + ->apply() — the only form that wins at hot-path sizes

> Construction is a fixed cost, so it decides the small sizes and vanishes at the large ones. Hoisting it out of the call is what gives the abstraction a hot path at all.

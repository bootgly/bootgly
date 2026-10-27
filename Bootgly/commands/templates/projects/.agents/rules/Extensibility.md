# Extensibility

A project may extend or improve a Bootgly core or platform piece before upstream ships it: a proposal
ready to move, never a patch to `Bootgly/`, `Console/` or `Web/` (see *Architecture principles*).

## Placement

- **SHOULD** — Put such a piece in `projects/<Name>/<Workable>/Bootgly/` — `<Workable>` is the program
  being worked on (`App`, `API`, …), the folder of its `<Workable>.Project.php`; a project without one
  extends nothing — at the path of its upstream namespace, so moving it drops the prefix:
  `<Name>\<Workable>\Bootgly\WPI\…` → `Bootgly\WPI\…`, `…\Bootgly\Web\…` → `Web\…` (`Console` alike).
  Its root holds only the core's layer folders and `commands`, and the platforms (`Console`, `Web`).
- **SHOULD** — One copy per project: in the first workable that needs it; another workable imports it.
- **SHOULD** — What no other project would use (bootstrap wiring, test harness, fixtures, domain) stays
  out; the pieces' tests go in the workable's `tests/`.

## Dependencies

- **MUST** — Imports run one way, or a piece cannot move upstream: layers `ABI` < `ACI` < `ADI` <
  `API` < `CLI` < `WPI` in the core, roots `Bootgly` < `Console` < `Web`. A piece never imports a later
  layer or root (a piece that needs one goes under it) nor project code, which may import any piece; a
  mirror counts as what it mirrors. Inside a layer or platform, depend as the pinned source does.

## Upstream shape

- **SHOULD** — The same path and class name as an upstream entity propose its successor: extend it
  (imported under an alias) or keep its verbs and named arguments. A new name proposes a new piece,
  named as upstream would.
- **SHOULD** — Record each gap and deviation from upstream in the project's notes.
- **RECOMMEND** — Once the release the kit pins ships a piece, move its users there and delete yours.

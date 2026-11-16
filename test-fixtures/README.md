# Test fixtures

Real ECU dumps used by `tests/Unit/GoldenFixturesTest`. **The dumps and `manifest.json` are ignored
by git**: they are customer or vehicle data and must not be published. Only this README and
`manifest.example.json` are in the repository.

## Setup

1. Copy `manifest.example.json` to `manifest.json`.
2. Put the files it lists in place (or edit the list to match the files you have).
3. Run `php artisan test tests/Unit/GoldenFixturesTest.php`.

Without a `manifest.json` the golden tests are skipped. With one, a listed file that is missing or
does not match its SHA-1 fails the run.

## Manifest fields

| Field | Meaning |
|---|---|
| `file` | Path below `test-fixtures/` |
| `sha1` | SHA-1 of the file (`shasum file`) |
| `note` | What the file is: vehicle, state, which tool wrote it |
| `family`, `sw` | Expected family id and software number |
| `checksum.module` | Expected checksum module name, or `null` when none exists |
| `checksum.ok` | `true`, `false`, or `null` (no module / cannot tell) |
| `checksum.blocks` | Optional: expected number of checksum blocks |
| `mutate` | Optional: hex offset of a calibration byte. Enables the change → correct → verify test |
| `max_correction_bytes` | Optional: upper bound for bytes written by the correction (default 1024) |
| `corrects_to` | Optional: path of the same file as corrected by a trusted tool; output must be byte-identical |
| `maps` | Optional: maps that must be found: `address` (hex), `rows`, `cols`, `name` (prefix match) |

## What to add

- One stock read per software family you support.
- For checksum work: the uncorrected tune and the same tune corrected by WinOLS or the flash tool
  (`corrects_to`). That pair is the proof a correction is right.
- When a detection bug is fixed on a real file, add that file and the map to `maps`.

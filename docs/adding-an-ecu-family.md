# Adding an ECU family

A family is one class implementing `App\Ecu\Families\EcuFamily`. The core (Analyzer, editor, gate,
dyno, Stage 1, review) does not need to change.

## Steps

1. **Collect fixtures first.** Put at least one stock dump in `test-fixtures/<family>/`, add it to
   `test-fixtures/manifest.json` with its SHA-1, family id and SW number. A stock file plus the same
   tune corrected by a trusted tool is what makes a checksum module provable.

2. **Create the family class** in `app/Ecu/Families`:

   - `id()`: short stable id (`edc17`, `hitachi_m32r`). It is stored on files and versions.
   - `detect(Bin)`: return `null` unless the file is positively this family. Prefer fixed-position
     signatures, known sizes and structure over a text search. Return `score` (0–100), `family`,
     `variant`, `cpu`, `endian`, and whatever identification you can read (`hw`, `sw`,
     `partNumber`, `swVersion`, `engine`). Put caveats in `notes`.
   - `findMaps(Bin, ident)`: return `MapDef[]`. For Bosch diesels extend `BoschDieselFamily`, which
     runs `AxisScanner` + `DieselClassifier`; override `bigEndian()`, `calibrationRange()` and
     `durationSets()`. For anything with a lookup-routine architecture, follow `Me7Family`
     (`Me7\LookupScanner` + `Me7\MapCatalog`).
   - `checksum(Bin)`: return a `ChecksumModule` or `null`. See `docs/checksums.md`.

3. **Register it** in `Analyzer::__construct()` (the list of family classes).

4. **Tests.** Synthetic layouts in `tests/Unit` (build them with `tests/Support/SyntheticBin`) for
   every structural rule you add, and fixture entries (`maps`, `checksum`, `mutate`) for the real
   file. Run the fixtures of the existing families too: scanner and classifier changes are shared.

## Rules that keep files safe

- **Map values:** `MapDef` rows are the first stored axis for layouts where values are stored per
  point of the first axis (Bosch EDC15 layout A, EDC16/17 layout B). Verify the order on a real map
  that is not square before trusting it: a transposed map still has plausible min/max values.
- **Names are claims.** Give a map a role-bearing name ("Torque limiter", "Driver's wish", "Smoke
  limiter", "Boost pressure target") only when shape, axes and values all agree. Stage 1 and the
  dyno act on these names through `Maps\MapRoles`. When unsure, leave it as "Map 16x16".
- **Confidence** below 0.5 keeps a map out of the "Key maps" list; use it.
- **Checksums:** `canFix()` stays false until fixtures prove the correction.
- **Never guess** addresses, algorithms or scalings that cannot be verified from code or files.
  Ask the owner, or leave the feature out and say so in `notes`.

## What a family does not have yet

File kind (full flash, calibration-only, EEPROM), memory layout and patch hook points are not part
of the contract. They are roadmap items 3 and 10 in `docs/audit-and-roadmap.md`.

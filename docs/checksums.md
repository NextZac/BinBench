# Checksums and the export gate

## The rule

A modified file is exported as **verified** only when every checksum block validates after
recalculation. Everything else is **unverified**: blocked by default, exportable only with an
explicit acknowledgement that is stored with the version, and downloaded as `…_UNVERIFIED.bin`.

`App\Ecu\Export\ExportGate::prepare()` enforces this and is the only code path that turns a working
buffer into a stored version (`EcuFileController::saveVersion`).

| Situation | Result |
|---|---|
| All blocks valid, nothing to do | verified, no byte changed |
| Blocks invalid, module can correct, result validates | verified, `corrected = true` |
| Blocks invalid, module can only check | blocked → unverified if acknowledged |
| Correction switched off by the user and blocks invalid | blocked → unverified if acknowledged |
| No checksum module for the ECU | blocked → unverified if acknowledged |
| Correction throws, changes the size, or does not validate | blocked → unverified if acknowledged |

The gate also refuses to work when the stored original no longer matches its recorded SHA-1.

## No silent byte changes

The gate itself never writes a byte. The only bytes that may differ between the buffer the editor
sent and the exported file are those written by the checksum module, and they are recorded:
`changes.correction` on the version holds the byte count and the ranges. `changes.maps` and
`changes.unmapped` are computed on the server by `BinDiff` from the original and the buffer; the
browser's own idea of what changed is ignored.

Measured on the fixtures after changing one calibration byte:

| File | Bytes written by the correction |
|---|---|
| ME7.1.1 `1037363946` | 22 bytes in 8 ranges (multipoint, main data and main program checksums) |
| BMW DDE4 `1037351632` | 4 bytes at `0x7BFFC–0x7BFFF` (calibration block checksum) |

## Writing a checksum module

Implement `App\Ecu\Checksum\ChecksumModule`:

- `supports(Bin)`: true only when the layout is positively recognised (a block that already
  validates, a signature at a fixed place). A wrong `true` is worse than a `false`: `false` leads to
  "unverified", a wrong `true` leads to a confidently wrong correction.
- `check(Bin)`: one entry per block with `name`, `start`, `end`, `stored`, `calculated`, `ok`.
- `canFix()`: false until the correction is proven on real stock/corrected pairs.
- `fix(Bin)`: return a corrected copy of the same size that passes `check()`, or throw. Never return
  a partly corrected file.

A module is not done until it has fixture entries (below). Record in the class docblock what the
algorithm was derived from and which files it was verified on.

## Golden fixtures

Real dumps live in `test-fixtures/` (ignored by git) and are described in
`test-fixtures/manifest.json`. `tests/Unit/GoldenFixturesTest` runs these checks per file:

1. the file matches its recorded SHA-1;
2. family and SW number are identified as recorded;
3. the checksum module, verdict and block count are as recorded;
4. exporting the untouched file changes no byte (or is blocked, for files that cannot be verified);
5. `mutate`: flipping one bit at that offset invalidates a checksum, the gate corrects it, the edit
   survives, the correction writes a small listed set of bytes, and a second run changes nothing;
6. `corrects_to`: the corrected output is byte-identical to a reference file from another tool;
7. `maps`: the listed maps are found at their address with the right size and name.

Add at least one stock file per SW family, and a `corrects_to` pair wherever you have the same tune
corrected by WinOLS or the flash tool: that pair is the strongest proof a module is right.

## Known gaps

- **EDC16C (BMW, PSA, Opel):** no module. Every export is unverified.
- **EDC16U (VAG):** verify only, and not yet confirmed on real files in this project.
- **EDC17 / MED17:** block checksums verify only; RSA signature and monitoring checksums not covered.
- **ME7 RSA re-signing** replaces the modulus with a freshly generated key (as ME7Sum does), so
  that correction is not reproducible byte for byte between runs.

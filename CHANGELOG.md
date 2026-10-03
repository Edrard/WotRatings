# Changelog

## Unreleased

- Add offline ER, WN6 and short/full WN8 calculations with validated per-instance expected values and isolated per-call overrides.
- Add PlayerDataPreparer for decoded WG responses, mode selection, battle-total checks and supplied full-tank fallback with explicit unavailable reasons.
- Expose independently editable prepared input objects; validate modifications again at calculation time.
- Apply short-first source selection to ER, WN6 and WN8, with coherent full-counter fallback and an explicit tankstats source override.
- Report available modes and per-rating readiness immediately; accept full tank records or API responses later and refresh readiness.
- Accept decoded encyclopedia/vehicles envelopes as well as extracted tier metadata.
- Add historical LegacyRatings and offline synthetic characterization tests.
- Add optional separate XVM expected-table verification; defer WgParser integration.

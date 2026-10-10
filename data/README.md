# UDS reference data

These files hold the published figures and code mappings the UDS Report uses. They change when HHS or HRSA publish something new. You can check or update them without reading any PHP.

| File | What it holds | Changes when |
|---|---|---|
| [poverty-guidelines.json](poverty-guidelines.json) | HHS poverty guidelines by year and region, for Table 4 income Lines 1–5 | HHS publishes new guidelines (each January) |
| [race-ethnicity.json](race-ethnicity.json) | Which CDC race and ethnicity codes go to which Table 3B line and column | HRSA changes the Table 3B lines |
| [insurance-categories.json](insurance-categories.json) | The UDS insurance category for each payment typology group and OpenEMR claim type | A mapping turns out to be wrong, or HRSA changes the insurance lines |
| [table-6a.json](table-6a.json) | The lines of Table 6A and the codes that put a visit on each, in the manual's notation | HRSA publishes a new manual (codes change every year) |

Each file begins with an `about` that explains its fields.

## What to check

Reviews from people who prepare UDS submissions are the most useful. In particular:

- **insurance-categories.json:** do you agree with each category? A `null` category means undecided. Each null has a note saying why, and the clinic chooses in Settings.
- **race-ethnicity.json:** does each subgroup and Hispanic origin go to the right line or column?
- **table-6a.json:** does each line carry the codes your manual shows? The file was checked against the 2026 manual token by token, but a second reader is worth more. Lines marked `partial` or `none` say what is missing.
- **poverty-guidelines.json:** the 2025 Alaska and Hawaii rows are empty because those figures haven't been checked against HHS yet. Filling them in takes the HHS notice and two numbers each.

## Editing

- Cite your source. Every poverty-guideline row with figures needs a `source`, and every undecided insurance category needs a `note`.
- Leave a figure empty (`null`) rather than estimating it. The report shows a missing year or region as "not loaded" and never guesses.
- The files are checked strictly when the report runs. A misspelled field, a number typed as text, a code in the wrong group or a duplicate row stops every UDS page with a message that names the file and entry. The report never runs on a broken file.
- If you open a file in a spreadsheet program, don't save it from there. Spreadsheets can turn codes like `2029-7` into dates. Use a text editor.

## Test cases

[cases/](cases/) holds the expected results: race and ethnicity placement, insurance categories, poverty thresholds and income lines, Table 6A counts, and whole tables built from synthetic patients worked out by hand from the 2026 UDS Manual. They are plain data so that any program implementing these rules, not only this module, can be held to the same results.

After any change, run the isolated tests (`openemr-cmd phpunit-isolated`, or `composer phpunit-isolated` on the host). A change that alters a result should change the matching case too, with the reason in its name or `why`.

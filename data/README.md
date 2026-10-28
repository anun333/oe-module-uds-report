# UDS reference data

These files hold the published figures and code mappings the UDS Report uses. They change when HHS or HRSA publish something new. You can check or update them without reading any PHP.

| File | What it holds | Changes when |
|---|---|---|
| [poverty-guidelines.json](poverty-guidelines.json) | HHS poverty guidelines by year and region, for Table 4 income Lines 1–5 | HHS publishes new guidelines (each January) |
| [race-ethnicity.json](race-ethnicity.json) | Which CDC race and ethnicity codes go to which Table 3B line and column | HRSA changes the Table 3B lines |
| [insurance-categories.json](insurance-categories.json) | The UDS insurance category for each payment typology group and OpenEMR claim type | A mapping turns out to be wrong, or HRSA changes the insurance lines |
| [table-5.json](table-5.json) | The lines of Table 5, which report visits and patients, what makes a visit virtual, and the diagnoses that make a behavioral health visit a substance use disorder visit | HRSA publishes a new manual |
| [table-5-taxonomy.json](table-5-taxonomy.json) | The Table 5 line suggested for each NUCC taxonomy code (from the manual's Appendix A) | NUCC publishes a new taxonomy (twice a year), or HRSA changes Appendix A |
| [table-6a.json](table-6a.json) | The lines of Table 6A and the codes that put a visit on each, in the manual's notation, with the value sets (OIDs only) each line names | HRSA publishes a new manual (codes change every year) |
| [table-9d-adjustments.json](table-9d-adjustments.json) | Where the Table 9D worksheet suggests each adjustment reason goes: Column D, Line 15 (bad debt), or review | A clinic adds its own adjustment reasons, or a suggestion turns out to be wrong |
| [ecqm-measures.json](ecqm-measures.json) | The eCQMs UDS Tables 6B and 7 use each year, with the line and the version the manual names | HRSA publishes a new manual (measure versions change every year) |
| [hrsa-checks.json](hrsa-checks.json) | HRSA's edit checks that read only figures this module computes, rebuilt from the edit messages HRSA publishes with each year's UDS data: what each compares, its threshold, HRSA's edit numbers, and how often it agreed with HRSA in 2025 | HRSA publishes a new year of UDS data (each summer): regenerate, don't edit by hand |
| [hrsa-published-2025.jsonl.gz](hrsa-published-2025.jsonl.gz) | Every health center's and look-alike's 2025 UDS figures as HRSA published them, for the tables this module computes, by grant or look-alike number: last year, for the HRSA checks page. Public domain | HRSA publishes a new year of UDS data: add the new year's file |

Each file begins with an `about` that explains its fields (the published figures file has it on its first line).

The last two are generated from HRSA's yearly UDS data download (data.hrsa.gov, the H80 and LAL workbooks) by the scripts in the openhealthcare workspace (`dev/uds_edits/`), which record each workbook's SHA-256. HRSA hides counts from 1 to 15 in that download (it prints `--`), so a small count from last year is unknown here, never zero. It also withholds whole tables for some organizations (it prints `---`; in 2025, Table 5 for 746 of 1,526), which are left out rather than read as zeros.

## What to check

Reviews from people who prepare UDS submissions are the most useful. In particular:

- **insurance-categories.json:** do you agree with each category? A `null` category means undecided. Each null has a note saying why, and the clinic chooses in Settings.
- **race-ethnicity.json:** does each subgroup and Hispanic origin go to the right line or column?
- **table-5-taxonomy.json:** would you put each kind of provider on that line? Codes no rule covers (about 80 rare ones, such as art therapists or athletic trainers) get no suggestion, and the clinic chooses.
- **table-6a.json:** does each line carry the codes your manual shows? The file was checked against the 2026 manual token by token, but a second reader is worth more. Lines marked `partial` or `none` say what is missing. The value sets' codes aren't here: each site fetches them with its own UMLS API key, because the UMLS licence doesn't allow passing CPT or SNOMED CT codes on.
- **table-9d-adjustments.json:** would your auditor treat each reason that way? Small-balance write-offs, administrative adjustments and returned checks are left for review on purpose. Add a rule for each reason your clinic has added to OpenEMR's Adjustment Reasons list.
- **poverty-guidelines.json:** the 2025 Alaska and Hawaii rows are empty because those figures haven't been checked against HHS yet. Filling them in takes the HHS notice and two numbers each.

## Editing

- Cite your source. Every poverty-guideline row with figures needs a `source`, and every undecided insurance category needs a `note`.
- Leave a figure empty (`null`) rather than estimating it. The report shows a missing year or region as "not loaded" and never guesses.
- The files are checked strictly when the report runs. A misspelled field, a number typed as text, a code in the wrong group or a duplicate row stops every UDS page with a message that names the file and entry. The report never runs on a broken file.
- If you open a file in a spreadsheet program, don't save it from there. Spreadsheets can turn codes like `2029-7` into dates. Use a text editor.

## Test cases

[cases/](cases/) holds the expected results: race and ethnicity placement, insurance categories, poverty thresholds and income lines, Table 6A counts, and whole tables built from synthetic patients worked out by hand from the 2026 UDS Manual. They are plain data so that any program implementing these rules, not only this module, can be held to the same results.

After any change, run the isolated tests (`openemr-cmd phpunit-isolated`, or `composer phpunit-isolated` on the host). A change that alters a result should change the matching case too, with the reason in its name or `why`.

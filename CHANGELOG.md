# Changelog

## 0.7.0 — 2026-10-12

- **Tables 6B and 7, the eCQM lines**, on their own page from the UDS Report, with a CSV download. Calculated by OpenEMR's PHP eCQM engine with the measure versions the 2026 manual names.
  - The manual's column logic: Column A is the denominator less exclusions and less exceptions who don't meet the numerator; Column B equals A; Column C is the numerator; each patient counts once; only patients with a countable UDS visit count.
  - Line 12 needs all three of CMS155's numerators; Line 14a is CMS138's third population; Lines 23a and 23b are CMS137's initiation and engagement over one denominator (read from each measure's CQL).
  - Table 7's blood pressure and diabetes sections by race and ethnicity, from each patient's Table 3B cell by Appendix B's crosswalk, in the manual's line order, with subtotals.
  - Measures not installed at the manual's version are named, not guessed at; so are the lines with no eCQM.
- **The calculation is stored and resumable.** An administrator starts it from the page (50 patients per request) or with `bin/uds-quality.php` on the server; each batch is saved with its progress in one transaction. New tables `uds_quality_run` and `uds_quality_result`, also in `sql/0_6_0-to-0_7_0_upgrade.sql` for Module Manager's upgrade.
- Needs OpenEMR's PHP eCQM engine (master after 8.4) and the 2026 measures (openemr/openemr#14536, not yet merged).
- Checked on OpenEMR master with #14536: per-patient results identical to `openemr:cqm-calculate`; for 2,009 synthetic patients the CMS165 results match the prediction exactly, by race and ethnicity too. About 0.09 seconds per patient across 15 measures.

## 0.6.0 — 2026-10-11

- **eCQM readiness for Tables 6B and 7**, on its own page from the UDS Report. The module doesn't produce Tables 6B and 7 yet; this shows what to fix before it can.
  - The 16 eCQMs UDS 2026 uses (`data/ecqm-measures.json`, each line and version checked against the manual), and whether each is installed at that version.
  - Counts of the records OpenEMR's eCQM calculation can't see, with how to fix each: visits with no coded Encounter Type (and the Encounter Types with no code), problems not classified as Diagnosis or with no code, and blood pressures recorded only in the Vitals form.
  - Ongoing problems and visits with no end time, which OpenEMR currently doesn't count ([openemr/openemr#14526](https://github.com/openemr/openemr/issues/14526)). The page asks OpenEMR's own QDM builder, so the warning goes away once OpenEMR is fixed.
- Checked end to end on OpenEMR 8.4.1 with hand-worked counts, and on OpenEMR master, where the builder check was shown to change with a local fix for #14526.

## 0.5.0 — 2026-10-11

- **Table 9D worksheet**, for whoever prepares Table 9D (Accrued Patient Service Revenue). Table 9D follows the audited financial statements and partly comes from the general ledger, so this is an input to it, not the table.
  - Charges by date of service, reclassified as FAQ 4 asks: each payer is charged what it settled, and the balance still open goes to whoever owes it now, the next insurer or the patient. Each line's charges before reclassification are shown too.
  - Collections and adjustments by posting date, on the line of the payer that posted them.
  - Adjustments sorted by the reason posted with them into Column D or Line 15 (bad debt), by `data/table-9d-adjustments.json`, which covers OpenEMR's stock reasons and what its payment screens, ERA posting and Collections report post. Unclear reasons are listed for review, not guessed.
  - Flags insurers not mapped to a UDS category, payments not applied to any visit, credit balances, old COPAY lines and product sales (pharmacy revenue is Line 17, which OpenEMR can't separate from supplies).
  - A page and a CSV download, opened from the Universal Report. Needs `acct/rep_a`, the permission of OpenEMR's own Collections report.
- Amounts are summed in whole cents, so totals are exact.
- The CSV download no longer puts an apostrophe before a negative amount such as `-12.50`, which a spreadsheet reads as a number, not a formula. Every other cell starting with `=`, `+`, `-` or `@` is still guarded.
- A test now rejects apostrophes in translated text, which OpenEMR shows as backticks.
- Checked end to end on OpenEMR 8.4.1, with payments and adjustments posted through OpenEMR's EOB posting screen and every figure worked out by hand first.

## 0.4.0 — 2026-10-11

- **Grant Reports for 330(g), 330(h) and 330(i).** Health centers with more than one Health Center Program funding type file one for each special population alongside the Universal Report.
  - Choose the report next to the year, on the report page and in the CSV download (`uds-YEAR-330h-tables.csv`, for example).
  - Each covers the population's patients and all their in-scope visits and services, on Tables 3A, 3B, 4, 5 (without FTEs) and 6A. The Patients by ZIP Code table is left out, as the manual says.
  - Membership is the Table 4 test for the same population, so a Grant Report's total equals the Universal Report's Table 4 Line 16, 23 or 26, and no Grant Report cell can be larger than the Universal Report's.
  - Records to fix lists the problems in the chosen report's patients.
- Tests check every Table 3A, 3B and 4 cell of each Grant Report against the Universal Report. Checked end to end on OpenEMR 8.4.1.
- **Fixed: apostrophes in Table 5 and 6A text showed as backticks** ("Alzheimer`s disease", since 0.2.0). OpenEMR's `xl()` turns apostrophes into backticks, and the labels and notes from the data files went through it although they have no translations. They're now shown as written.

## 0.3.0 — 2026-10-11

- **Table 5: Staffing and Utilization**, clinic and virtual visits and patients by type of service and personnel. FTEs are left to HR records.
  - Each visit goes on its provider's line: chosen in Settings, or suggested from the provider's NUCC taxonomy code (`data/table-5-taxonomy.json`, written from the manual's Appendix A and checked against NUCC taxonomy 26.1).
  - A visit category can send all its visits to one line (case management or health education by a nurse, for example).
  - Mental health and substance use disorder visits go by their first diagnosis, not the provider's title.
  - One visit per patient, service category, site and day, as the manual counts them; virtual visits from telehealth codes, modifiers 95 and 93, place of service 02 or 10, or OpenEMR's Virtual Encounter class.
  - The report lists each provider's line and how it was chosen, flags providers still on OpenEMR's default taxonomy code, and counts encounters that aren't Table 5 visits and UDS patients with no Table 5 visit.
  - On the report page and in the CSV download.
- Tables 3A–4 and Table 5 read countable visits through one shared query, so they can't disagree about which visits count.
- Hand-worked Table 5 cases in `data/cases/table-5.json`. Checked end to end on OpenEMR 8.4.1, with codes saved through OpenEMR's own billing functions and a line chosen through the Settings page.

## 0.2.0 — 2026-10-10

- **Table 6A: Selected Diagnoses and Services Rendered**, from the billing codes on each encounter.
  - Diagnoses count at countable visits; services at any in-scope encounter in the year for UDS patients.
  - One visit per line, a patient once per line, and age limits by age at the visit.
  - Codes are in `data/table-6a.json`, copied from the 2026 UDS Manual in its own notation (all 326 code tokens and 21 value set OIDs checked against the manual).
  - 7 lines defined only by value sets are left blank, and 11 lines count only the listed codes. The report marks each, with the reason.
  - On the report page and in the CSV download, now named `uds-YEAR-tables.csv`.
- Hand-worked Table 6A cases in `data/cases/table-6a.json`, including exclusions, the J40-with-U07.1 rule, age boundaries, and services at visits that aren't countable.
- Checked end to end on OpenEMR 8.4.1, with codes saved through OpenEMR's own `BillingUtilities::addBilling()`.
- The footprint generator now reads SQL built by concatenation, and SQL inside shared column lists.

## 0.1.1 — 2026-10-09

Found by entering data through OpenEMR's own Demographics form and reading the
report back (an end-to-end run against OpenEMR 8.4.1).

- **Monthly income is read only when it is one amount** (`2000`, `$2,000`,
  `2,000.50`). 0.1.0 stripped every non-digit, so `$2,000 - $2,500` became
  $20,002,500 a month (Over 200%), `1.5k` became $1.50, and `-500` became
  +$500, each landing on a real poverty line without a warning. Anything that
  is not one amount now counts as Unknown.
- **Records to fix names why income can't be used**, instead of one catch-all
  line: income not recorded, income not understood, family size not recorded,
  family size not a whole number, Financial Review Date not recorded, the date
  **not saved** (OpenEMR stores a date typed any way but `YYYY-MM-DD` as no
  date), or the review outside the 12 months before the last visit.
- The Housing Status and Agricultural Worker fields' help text now says the
  report reads them, not OpenEMR's free-text Homeless and Migrant/Seasonal
  fields beside them. Applies to new installs.

## 0.1.0 — 2026-10-09

First pre-release: Patients by ZIP Code and Tables 3A, 3B and 4 per the 2026
UDS Manual, with a list of records to fix before submission.

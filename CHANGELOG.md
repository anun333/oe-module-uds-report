# Changelog

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

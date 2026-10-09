# Changelog

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

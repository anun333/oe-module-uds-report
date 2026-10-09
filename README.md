# UDS Report for OpenEMR

This module produces the demographic part of the HRSA **Uniform Data System (UDS)** report, which every Health Center Program awardee and look-alike files each year. It covers the **Patients by ZIP Code** table and **Tables 3A, 3B and 4**, following the **2026 UDS Manual** (calendar year 2026, due February 15, 2027). It also lists the patient records that would make the tables wrong, so staff can fix them before submission.

It doesn't submit anything. Health centers still enter the figures in HRSA's Electronic Handbooks (EHBs), and should check them against their own records first.

> **Status: 0.1.0, pre-release.** The counting rules are tested against hand-worked synthetic cases only. They have not yet been compared with a health center's real UDS submission. If your health center uses OpenEMR and would compare this module's table totals with a report you have already filed (totals only, no patient data shared), please open an issue.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it gives you

- **Reports › UDS Report:** the four tables for a chosen year, in the manual's layout and line numbering. Also:
  - a cross-table check (the manual requires the same patient total on every table),
  - a CSV download, and
  - a "Records to fix" list. **Blocking** problems keep a patient off a table entirely. **Quality** problems push a patient into an "unknown" line.
- **Records to fix › patient list:** the patients behind each problem, each opening the chart. Viewing the list is written to OpenEMR's audit log.
- **Settings** (administrators only):
  - which visit categories count as UDS visits,
  - which sites are in the scope of project, school-based, or in or next to public housing,
  - the poverty-guideline region, and
  - the UDS insurance category of each insurance company.
- **Three coded demographic fields,** added to the Demographics **Stats** group:
  - **Housing Status (UDS):** housed, shelter, transitional, doubling up, street, permanent supportive housing, other homeless arrangement, homeless (arrangement unknown), or declined.
  - **Agricultural Worker (UDS):** migratory, seasonal, aged or disabled former migratory worker, or declined.
  - **Military Service (UDS):** served (not on active duty now), active duty, has not served, or declined.

  OpenEMR's own "Homeless" and "Migrant/Seasonal" fields are free text, which the UDS can't use. They are left untouched.

## How it counts

| Rule (2026 UDS Manual) | Implementation |
|---|---|
| A patient has at least one countable visit in the year | An encounter in the year with a provider, in a countable visit category (default: every patient category except No Show), at an in-scope site. Record labs, injections, screenings and outreach under categories you leave unticked. |
| Age is as of December 31 | `DOB` |
| Sex as listed on the birth certificate, with no unknown column (3A) | OpenEMR's `sex` field. Anyone not recorded as Male or Female is listed as a blocking problem. |
| Race lines 1a–7, ethnicity columns A1–C (3B) | CDC codes from the race and ethnicity lists, rolled up to UDS lines ([data/race-ethnicity.json](data/race-ethnicity.json)). Several Asian subgroups go to 1g, several Pacific Islander subgroups to 2b, two or more race groups to 6. A race without an ethnicity answer is presumed not Hispanic. The stock ethnicity list has only Hispanic yes/no, so Hispanic patients go to A5 unless the list carries detailed CDC codes. |
| Limited English proficiency (3B, Line 12) | Preferred language isn't English (or blank or declined), or an interpreter is needed |
| Income recorded at or within 12 months before the last visit, compared with the HHS poverty guidelines for the health center's location | `monthly_income` × 12 and `family_size`, counted only when the Financial Review Date falls in that window. Guidelines are in [data/poverty-guidelines.json](data/poverty-guidelines.json): 2026 for all regions, 2025 for the 48 contiguous states only. |
| Primary medical insurance at the last visit, by age 0–17 / 18+ | The primary policy in effect on the last visit date. Medicare primary with Medicaid also active counts as dually eligible (Line 9a). The category comes from the clinic's setting, then the payer typology group, then the claim type ([data/insurance-categories.json](data/insurance-categories.json)). An insurer that can't be decided is a blocking problem; nothing is guessed. |
| ZIP codes with 10 or fewer patients combine into "Other ZIP Codes"; people experiencing homelessness without a ZIP use the site's ZIP | As stated |
| Lines 24 (school-based) and 26 (public housing) depend on the visit site | Sites marked in Settings |
| Line 25 counts only an affirmative answer, never active duty | "Served" only |

## Reference data

The published figures and code mappings are in [data/](data/README.md), in plain files that people who prepare UDS submissions can check without reading PHP: the poverty guidelines, the race and ethnicity roll-up, and the insurance categories. The counting rules stay in the code. A broken data file stops the report with a message naming the file and entry; it never runs on a bad edit.

The expected results are data too ([data/cases/](data/cases/)): line and column placements, insurance categories, poverty thresholds, and whole tables built from synthetic patients worked out by hand from the manual. Any program implementing the same rules can be held to them. The tests run every case (`tests/ConformanceTest.php`). The full report has also been checked end to end against a running OpenEMR with synthetic patients whose every cell was worked out by hand first.

## Not done yet, or approximated

- **Other tables:** 5, 6A, 6B, 7, 8A, 9D and 9E, the two forms, and the per-grant reports (330(g), (h) and (i) subsets) aren't produced yet. Tables 6B and 7 can build on OpenEMR's existing eCQM engine once its measure package covers the 2026 versions.
- **Countable visits:** whether a visit is truly countable (independent judgment, a credentialed provider, not a nurse-only or ancillary service) is approximated by visit category and provider. Each health center has to set the categories.
- **Homelessness and farmworker look-back:** the manual counts someone as homeless for 12 months after homelessness was last documented, and as a farmworker if that was their work within 24 months. The module uses the current value of each field; it has no history of past values.
- **Medicaid versus CHIP:** the two can't be told apart automatically. They're reported as Medicaid unless a plan is set to CHIP in Settings, which is what the manual says to do when they can't be distinguished.
- **Validation:** tested only with synthetic data. It has not been compared with a health center's real submission.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-uds-report
```

That places it in `interface/modules/custom_modules/oe-module-uds-report/`. Then go to Modules › Manage Modules and **Register**, **Install** and **Enable** UDS Report. Installing adds the settings tables and the three demographic fields. Uninstalling leaves them in place, because they hold data staff entered.

Viewing needs `patients/demo`; Settings needs `admin/super`.

## Compatibility

Checked end to end on OpenEMR 8.4.1 (PHP 8.3 and later). The package declares no Composer constraint on `openemr/openemr`: OpenEMR reports itself to Composer as version `1.0.0+no-version-set`, so any minimum version written there would block every install.

## Development

```
composer install
vendor/bin/phpunit                 # the counting rules and data/cases/; no database needed
php tools/footprint.php --check    # FOOTPRINT.md must match the source
```

## License

GPL-3.0-or-later.

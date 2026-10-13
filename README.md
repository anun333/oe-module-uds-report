# UDS Report for OpenEMR

This module produces the demographic part of the HRSA **Uniform Data System (UDS)** report, which every Health Center Program awardee and look-alike files each year. It covers the **Patients by ZIP Code** table, **Tables 3A, 3B and 4**, **Table 5** (visits and patients by type of service), **Table 6A** (selected diagnoses and services), and the eCQM lines of **Tables 6B and 7** (quality of care and health outcomes, from OpenEMR's own eCQM engine), following the **2026 UDS Manual** (calendar year 2026, due February 15, 2027), as the Universal Report and as the **Grant Reports** for 330(g), (h) and (i) funding. It also lists the patient records that would make the tables wrong, so staff can fix them before submission, and gives whoever prepares **Table 9D** (patient service revenue) a worksheet from OpenEMR's accounts receivable.

It doesn't submit anything. Health centers still enter the figures in HRSA's Electronic Handbooks (EHBs), and should check them against their own records first.

> **Status: 0.7.0, pre-release.** The counting rules are tested against hand-worked synthetic cases only. They have not yet been compared with a health center's real UDS submission. If your health center uses OpenEMR and would compare this module's table totals with a report you have already filed (totals only, no patient data shared), please open an issue.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it gives you

- **Reports › UDS Report:** the tables for a chosen year, in the manual's layout and line numbering. Also:
  - a cross-table check (the manual requires the same patient total on every table),
  - a CSV download, and
  - a "Records to fix" list. **Blocking** problems keep a patient off a table entirely. **Quality** problems push a patient into an "unknown" line.
- **Grant Reports:** choose 330(g), 330(h) or 330(i) instead of the Universal Report, on the page and in the CSV. See [Grant Reports](#grant-reports).
- **Table 9D worksheet:** charges, collections and adjustments by payer line, reclassified as the manual asks, with each adjustment reason and where it suggests it goes. For staff who can open OpenEMR's Collections report. See [Table 9D worksheet](#table-9d-worksheet).
- **eCQM readiness** (Tables 6B and 7): whether this OpenEMR can calculate the year's measures, and which records the calculation can't see, with how to fix each. See [eCQM readiness](#ecqm-readiness).
- **Records to fix › patient list:** the patients behind each problem, each opening the chart. Viewing the list is written to OpenEMR's audit log.
- **Settings** (administrators only):
  - which visit categories count as UDS visits,
  - which sites are in the scope of project, school-based, or in or next to public housing,
  - the poverty-guideline region,
  - the UDS insurance category of each insurance company, and
  - the Table 5 line of each provider (suggested from their NUCC taxonomy code) and, optionally, of each visit category.
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
| Income recorded at or within 12 months before the last visit, compared with the HHS poverty guidelines for the health center's location | `monthly_income` × 12 and `family_size`, counted only when the Financial Review Date falls in that window. Monthly income must be **one amount** (`2000`, `$2,000`, `2,000.50`); a range, a unit or words is counted as Unknown, never guessed. Guidelines are in [data/poverty-guidelines.json](data/poverty-guidelines.json): 2026 for all regions, 2025 for the 48 contiguous states only. |
| Primary medical insurance at the last visit, by age 0–17 / 18+ | The primary policy in effect on the last visit date. Medicare primary with Medicaid also active counts as dually eligible (Line 9a). The category comes from the clinic's setting, then the payer typology group, then the claim type ([data/insurance-categories.json](data/insurance-categories.json)). An insurer that can't be decided is a blocking problem; nothing is guessed. |
| ZIP codes with 10 or fewer patients combine into "Other ZIP Codes"; people experiencing homelessness without a ZIP use the site's ZIP | As stated |
| Lines 24 (school-based) and 26 (public housing) depend on the visit site | Sites marked in Settings |
| Line 25 counts only an affirmative answer, never active duty | "Served" only |

## Table 5: Staffing and Utilization

Visits (Columns B and B2) and patients (Column C) by type of service and personnel. FTEs (Column A) come from HR records, which OpenEMR doesn't hold, so the column is left for the health center to enter in EHB.

| Rule (2026 UDS Manual) | Implementation |
|---|---|
| A visit goes on the line of the provider who saw the patient (page 59) | The line set for the visit's category in Settings, if any; else the line chosen for the provider in Settings; else the one suggested by the provider's NUCC taxonomy code ([data/table-5-taxonomy.json](data/table-5-taxonomy.json), from Appendix A) |
| Only providers generate visits; medical assistants, LPNs, lab and X-ray staff, pharmacists and others don't (page 64 and Appendix A) | Their lines report no visits, and LPNs and therapy assistants are marked as non-providers on the lines they share with providers. Their encounters are counted as "not Table 5 visits" on the report |
| Mental health and substance use disorder visits are classified by the primary service, not the provider's title (pages 54–55) | A visit by a mental health or SUD provider whose first diagnosis is an alcohol or other substance use disorder (Table 6A Lines 18–19) goes on Line 21; an SUD specialist's other visits go on Line 20c. A visit with no diagnosis stays on the provider's line, and the report counts them |
| Medical providers' visits are medical, even for opioid use disorder medication (FAQ 13) | Medical lines aren't reclassified |
| One visit per patient, service category, site and day; per provider type for other professional and patient support services; two different providers at two different sites make two visits (page 19) | Encounters are grouped that way. A group's visit goes to the line that comes first in the table (a physician before an NP before a nurse), then to the earliest encounter. The report counts the encounters folded into another visit |
| Virtual visits (Column B2): telehealth CPT and HCPCS codes, modifier 95 or 93, place of service 02 (page 60) | Those, plus place of service 10 and OpenEMR's Virtual Encounter class, listed in [data/table-5.json](data/table-5.json) |
| Patients are counted once in each service category, so Line 34 normally exceeds Table 3A (page 64, FAQ 16) | As stated. Every UDS patient should still have at least one Table 5 visit; the report lists the number who don't |

OpenEMR gives every new user the taxonomy code `207Q00000X` (Family Medicine). The report flags each provider still on it, and anyone whose code suggests no line, until a line is chosen for them in Settings.

## Table 6A: Selected Diagnoses and Services Rendered

Counted from the billing codes on each encounter (the fee sheet's codes). The manual names billing systems and health records as the sources, and its FAQ asks health centers to code a service even when there's no charge, rather than leave it out.

| Rule (2026 UDS Manual) | Implementation |
|---|---|
| Diagnoses (Lines 1–20g) count at countable visits | ICD-10 codes on countable visits (same test as above) |
| Tests, screenings, dental and support services (Lines 21–42) count when provided at any point in the year to a health center patient | Codes on any encounter in the year at an in-scope site, for patients with at least one countable visit. A flu shot at a nurse-only visit counts; one given to someone with no countable visit doesn't |
| Column A: one visit per line, however many of its codes match; a visit counts on every line it matches | As stated |
| Column B: a patient once per line | As stated |
| Age limits use the age at the visit (Lines 15–17, 26, 26a, 26e) | `DOB`; an unknown birth date can't satisfy an age limit |
| The codes for each line | [data/table-6a.json](data/table-6a.json), copied in the manual's own notation and checked against the manual code by code |

What it can't count yet (each line is marked on the report, with the reason):

- **7 lines defined only by value sets** (26c2, 26c3, 38, 39, 40, 41, 42: tobacco cessation drugs, opioid use disorder medications, language assistance, and the upstream drivers of health lines) are left blank. Counting them needs the Value Set Authority Center's expansions.
- **11 lines count the codes the manual lists but not their value sets.** On Line 21e (PrEP) the manual also requires a prescription for specific drugs, which isn't checked; on Line 26f (dementia screening) only CPT 99483 counts, not the screeners.
- **Codes outside the fee sheet aren't seen:** a lab result returned without a billing code, or an immunization recorded only in the Immunizations screen. The manual's preferred fix is to put the code on the encounter with a zero charge.
- **Dental Lines 27–32** count the codes whoever recorded them; the manual limits them to dental providers (except fluoride).
- **Dental codes** are recognised by their shape (D plus four digits) under any code type except ICD, since OpenEMR has no CDT code type.

## Grant Reports

A health center funded under more than one Health Center Program type files a Grant Report for each special population it is funded for, alongside the Universal Report (pages 11–12 and 15–16):

| Grant Report | Patients | Matches the Universal Report's |
|---|---|---|
| Migratory and Seasonal Agricultural Workers, 330(g) | Agricultural Worker (UDS) is migratory, seasonal, or an aged or disabled former migratory worker | Table 4, Line 16 |
| Homeless Population, 330(h) | Housing Status (UDS) is any homeless arrangement | Table 4, Line 23 |
| Residents of Public Housing, 330(i) | Seen at a site marked in Settings as in or next to public housing; it is a site-based count, so where the patient lives doesn't matter | Table 4, Line 26 |

Each covers those patients and **all** their in-scope visits and services, whatever paid for them (FAQ 2), on Tables 3A, 3B, 4, 5 and 6A. Table 5 has no FTEs, and the Patients by ZIP Code table isn't part of a Grant Report. The membership test is the one Table 4 uses, so a Grant Report's total patients equals the Universal Report's line for that population, and no Grant Report cell can be larger than the Universal Report's, as the manual requires.

## Table 9D worksheet

Table 9D follows the health center's audited financial statements, and part of it comes from the general ledger (FAQ 8, page 169), so the module gives a worksheet for whoever prepares it, not the table. It reads OpenEMR's accounts receivable: fee sheet charges and the payments and adjustments posted to them. It opens from the Universal Report; Grant Reports have no Table 9D.

| Rule (2026 UDS Manual) | Implementation |
|---|---|
| Charges (Column A) by date of service in the year, from the fee schedule (pages 164–165, FAQ 6) | Active fee sheet lines of visits dated in the year at in-scope sites. Sales tax lines are left out, and so are old COPAY lines (payments recorded as negative charges), which are reported |
| Charges are reclassified: each payer is charged what it accepted, and the rest moves to the next payer and finally to Self-Pay (page 165, FAQ 4) | Each payer is charged what it settled on the visit (the payments and adjustments posted for it, up to the day the report runs). The balance still open goes to whoever owes it now: the insurer at the level after the last one closed, or the patient. The page also shows each line's charges before reclassification |
| Collections (Column B) and adjustments (Column D) by posting date in the year, for services of any year (FAQ 6) | Postings dated in the year: the date entered with the posting, else its batch's Post To Date, else the day it was saved. Takebacks and refunds, posted as negative payments, reduce collections (page 168) |
| A payer line follows the payer, including managed care plans run by private insurers (pages 160–161) | The insurer that sent the payment, else the patient's insurer at that level on the date of service, by the UDS insurance categories set in Settings. Patient postings are Self-Pay |
| Contractual adjustments, sliding fee discounts and hardship waivers are Column D; bad debt is Line 15, not Column D (pages 162–167) | By the reason posted with each adjustment ([data/table-9d-adjustments.json](data/table-9d-adjustments.json)). Reasons with no clear treatment, and reasons the file doesn't know, are listed for review and kept out of Column D |
| Bad debt by the allowance method where possible; direct write-off otherwise (page 163) | Direct write-offs posted in the year. An allowance comes from the general ledger |
| Pharmacy revenue is Line 17, not charges (pages 164–166) | Product sales from OpenEMR's inventory and their payments are reported apart, because OpenEMR doesn't tell pharmaceuticals from supplies such as eyeglasses, which are charges |

Lines 17 and 18, capitation, settlements and wrap payments are left to the general ledger. Insurers not mapped to a UDS category, and insurers mapped to None/Uninsured (a program such as Title X is uninsured on Table 4 but Other Public on Table 9D), get their own rows so the total stays whole. The worksheet also reports payments not yet applied to any visit, and visits paid more than was charged.

## eCQM readiness

Most of Tables 6B and 7 are eCQMs, which OpenEMR calculates itself, and [the module reports them](#tables-6b-and-7). Their results are only as good as what the engine can see, so **Reports › UDS Report › eCQM readiness** shows what to fix first.

It lists the 16 measures UDS 2026 uses ([data/ecqm-measures.json](data/ecqm-measures.json)), the version the manual names, and whether that version is installed. It also counts, for the year's countable visits and the patients seen at them, the records OpenEMR's eCQM calculation can't see:

| Records | Why (measured on OpenEMR master c11dd1b15c with CMS165) |
|---|---|
| Visits with no coded Encounter Type | Visits are found only by the code of their Encounter Type, not by billing codes. OpenEMR's stock Encounter Types have no codes; the page lists those that don't |
| Problems not classified as Diagnosis, or with no code | Problems are read only when their Classification Type is Diagnosis, which a new problem doesn't have |
| Blood pressures only in the Vitals form | Blood pressure is read only from Observations of type Physical Exam Performed, a type the stock list has turned off |
| Ongoing problems, and visits with no end time | OpenEMR treats a missing end date as unknown, so these never count ([openemr/openemr#14526](https://github.com/openemr/openemr/issues/14526)). The page asks OpenEMR's own builder, so the row goes away once OpenEMR is fixed. Adding end dates to ongoing problems isn't a fix: an end date marks a problem resolved |

## Tables 6B and 7

**Reports › UDS Report › Tables 6B and 7** shows the eCQM lines of both tables for the year, calculated by OpenEMR's own eCQM engine with the measure versions the manual names, and a CSV download.

| The 2026 UDS Manual (pages 84–85) | What the module does |
|---|---|
| Count patients seen for a countable UDS visit who meet the eCQM's initial population and qualifying encounter | The engine is run for the year's UDS patients (the same countable-visit rule as the other tables) |
| Column A: the denominator, less exclusions, less exceptions who don't meet the numerator | As stated, per patient |
| Column B: records reviewed | Equal to Column A: the whole EHR is reviewed |
| Column C: the numerator; each patient counts once per measure | As stated |
| Line 12 (CMS155): BMI percentile **and** counseling for nutrition **and** for physical activity | A child in all three of the measure's numerators |
| Line 14a (CMS138): screened and not a user, or a user who received cessation intervention | The measure's third population set |
| Lines 23a and 23b (CMS137): initiation and engagement, over one denominator | The measure's first and second population sets |
| Table 7 by race and Hispanic, Latino/a, or Spanish ethnicity | Each patient's Table 3B cell, by Appendix B's crosswalk to the Table 7 line, with the subtotals |

**Calculating.** The engine is run once per year and the results are stored, because it takes about a tenth of a second per patient (measured with light synthetic patients; real records take longer). An administrator starts it from the page, which works through the patients 50 at a time and can be resumed if closed; for a large health center, run it on the server instead:

```
HTTP_HOST=localhost php interface/modules/custom_modules/oe-module-uds-report/bin/uds-quality.php --year=2026
```

**What it needs.**
- OpenEMR's PHP eCQM engine ([openemr/openemr#14469](https://github.com/openemr/openemr/pull/14469)), on OpenEMR master after 8.4.
- The 2026 measure files, in OpenEMR master since 2026-10-13 ([openemr/openemr#14536](https://github.com/openemr/openemr/pull/14536)). On an OpenEMR without them, the page names each measure that isn't installed.
- The [readiness page](#ecqm-readiness) cleared, above all [openemr/openemr#14526](https://github.com/openemr/openemr/issues/14526): until it's fixed, ongoing problems and visits with no end time don't count, and the page says so.

**Not calculated.** Line 18 (CMS164v7, which the manual keeps at an old version no measure bundle has); the lines with no eCQM (prenatal care, Lines 1–9; HIV linkage to care, Line 20; dental sealants, Lines 22a and 22b); and Table 7 Section A (deliveries and birth weight). The page lists them, to be reported from the health center's records.

## The Financial Review Date pitfall

OpenEMR's **Financial Review Date** is a plain text box on a date column, and OpenEMR saves with the database's strict checking off. A date typed as `2026-09-15` is kept; typed any other way (`9/15/2026`, `Sept 15`) it is **silently stored as no date at all**, with no message on screen. The patient's income then can't be used and counts as Unknown on Table 4. The Records to fix list names this case on its own ("Financial Review Date was not saved"), so staff can re-enter those dates.

## Reference data

The published figures and code mappings are in [data/](data/README.md), in plain files that people who prepare UDS submissions can check without reading PHP: the poverty guidelines, the race and ethnicity roll-up, the insurance categories, the Table 5 lines and taxonomy mapping, and the Table 6A codes. The counting rules stay in the code. A broken data file stops the report with a message naming the file and entry; it never runs on a bad edit.

The expected results are data too ([data/cases/](data/cases/)): line and column placements, insurance categories, poverty thresholds, and whole tables built from synthetic patients worked out by hand from the manual. Any program implementing the same rules can be held to them. The tests run every case (`tests/ConformanceTest.php`). The full report has also been checked end to end against a running OpenEMR with synthetic patients whose every cell was worked out by hand first.

## Not done yet, or approximated

- **Other tables:** 8A and 9E and the two forms aren't produced yet. Tables 6B and 7 cover their eCQM lines only ([above](#tables-6b-and-7)), and Grant Reports don't include them. Table 9D is a worksheet, for the reasons [above](#table-9d-worksheet).
- **Table 5 FTEs** (Column A) aren't computed; they come from HR records. A person who holds two roles (a nurse who is also a case manager, say) has one line per person, plus any line set by visit category.
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

Viewing needs `patients/demo`; the Table 9D worksheet needs `acct/rep_a`, as OpenEMR's Collections report does; Settings needs `admin/super`.

## Compatibility

| OpenEMR | Tables, Grant Reports, 9D worksheet, eCQM readiness | Tables 6B and 7 | How it was checked |
|---|---|---|---|
| master (8.5.0-dev) | works | works: the 2026 measures are in master since 2026-10-13 ([#14536](https://github.com/openemr/openemr/pull/14536)). Ongoing problems and visits with no end time don't count until [#14526](https://github.com/openemr/openemr/issues/14526) is fixed | end to end, synthetic patients; rechecked on master 76fa2f1e88 (2026-10-13): CMS165v14 for nine synthetic patients matched each one's prediction |
| 8.4.1 | works | no PHP eCQM engine; the page says so | end to end, synthetic patients |
| 8.4.0, 8.3.0, 8.2.0, 8.1.0 | installs, every page loads | no PHP eCQM engine | official Docker images: installed through Module Manager, every page loaded as an administrator with no PHP error (no patients) |
| 8.0.0 and older | won't run: OpenEMR classes it uses don't exist there (`AccessDeniedHelper`, `BC\ServiceContainer`, and others) | | which classes exist at each release tag |

PHP 8.3 or later is needed. On 8.1.0 to 8.3.0 the module reads the request itself, because OpenEMR's `CurrentRequest` only arrived in 8.4.0.

The 8.4.1 checks: Tables 5 and 6A from codes saved through OpenEMR's own billing functions, a Table 5 line chosen through the Settings page, and the Table 9D worksheet from payments and adjustments posted through OpenEMR's EOB posting screen. The eCQM readiness page was also checked against OpenEMR master (c11dd1b15c), where eCQMs are calculated in PHP, with and without a local fix for #14526. Tables 6B and 7 were checked on master (7001d5008b) with #14536's 2026 measures: the module's results for each patient are identical to OpenEMR's own `openemr:cqm-calculate`, and for 2,009 synthetic patients the CMS165 denominator (1,100) and numerator (443) match what each patient's records predict, by race and ethnicity too, calculated both from the page and from the command line.

The package declares no Composer constraint on `openemr/openemr`: OpenEMR reports itself to Composer as version `1.0.0+no-version-set`, so any minimum version written there would block every install.

## Development

```
composer install
vendor/bin/phpunit                 # the counting rules and data/cases/; no database needed
php tools/footprint.php --check    # FOOTPRINT.md must match the source
```

## License

GPL-3.0-or-later.

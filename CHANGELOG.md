# Changelog

## 0.17.1 (unreleased)

- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility (WCAG 2.1 AA):** every page declares its language (`<html lang>`). The settings page labels each checkbox and select by its column and row, and the poverty guidelines select has a label. Lines 14-22's grey text is now 7.6:1 on white (it was 2.5:1, from the theme's `--gray`). Links in running text are underlined.
- **README:** run OpenEMR on a PHP with PHP's php2shell fix (2026-10-21: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later). The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get it.
- **FOOTPRINT.md** now comes from openhealthcare-common's footprint tool (1.1), the one every module shares; `tools/footprint.php` only loads it. The sheet's closing notes, what it can't show, are this module's own (in composer.json). The sheet now lists the module's permission checks, the OpenEMR functions it calls and its audit event, which its old copy of the tool left out.
- **Checked:** unit tests; the nightly matrix on 8.4.1 and master (the UDS smoke, eCQM, 6A, 9D, grant evidence and Table 5 checks); fresh official 8.1.0, 8.3.0 and 8.5.0 images, with every module enabled and with it alone (on 7.0.2 and 7.0.3 it stays switched off, as designed).

## 0.17.0 (2026-10-23)

- **Service area check** (HRSA Scope of Project Policy Manual, 2026, Chapter 2: the service area includes at least the ZIP codes where 75% of current patients live, from UDS patient origin).
  - Under the Patients by ZIP Code table: the fewest ZIP codes reaching 75% of the year's patients with a known ZIP, each with its running share. Every ZIP code counts, including those folded into Other ZIP Codes; unknown residence is left out and its number shown.
  - **Settings:** the service area ZIP codes on Form 5B. The report names the 75% ZIP codes missing from Form 5B, how many patients live inside it, and Form 5B ZIP codes with no patient. Anything entered that isn't a five-digit ZIP code is named and left out.
- **Checked against master** a10ebfa8d3, with every UDS check in the nightly matrix on both stacks. The manual changes nothing in what the tables count (Chapter 8, footnote 111).

## 0.16.2 (2026-10-22)

- **No longer stops an older OpenEMR.** OpenEMR loads a module's bootstrap on every request, the login page included. This module's used `OEGlobalsBag`, which came with OpenEMR 7.0.4 and 8.0, so on 7.0.3 or older, enabling it made every page fail (HTTP 500), login included, until the module was switched off in the database. It now stays switched off there instead. Found by the nightly matrix's new fresh 7.0.2 install (`dev/module_compat_check.sh`).

## 0.16.1 — 2026-10-20

- **Records to fix: OpenEMR's own Homeless and Migrant/Seasonal fields.** The stock Demographics form has "Homeless, etc." (`patient_data.homeless`) and "Migrant/Seasonal" (`migrantseasonal`), both free text, which the UDS never reads. A patient with the UDS housing status or agricultural worker status empty, and something in the matching OpenEMR field, is now listed under its own entry that says so, in place of "not recorded". Nothing is counted from the free text. Found in an audit of where two modules or core record the same fact.

## 0.16.0 — 2026-10-20

- **Table 5: telehealth visits by their visit category.** Settings has a new list, "Visit categories that are virtual visits (Table 5, Column B2)".
  - A visit in a ticked category is virtual even with nothing else on it that says so.
  - By default the two telehealth categories that OpenEMR's Comlink telehealth module creates are ticked. Its encounters carry the AMB class and the site's own place of service, so before this a Comlink visit counted as in person unless billing added modifier 95 or 93 or a telehealth code.
  - A site that uses its own telehealth categories ticks them.
- **Records to fix: a social needs screen Line 39 can't count.** OpenEMR's older Social Screening Tool encounter form (`form_sdoh`, 2022) records no screening instrument code. Once Line 39 is counted (its value sets fetched), the patients screened only with that form are listed, with how to record the screen so it counts.
- `tools/footprint.php` refuses web requests. A test now fails on any translated string with an apostrophe or quote mark, which OpenEMR's `xl()` would show as a backtick (none were found here).

## 0.15.1 — 2026-10-19

- **Fixed: the Grant evidence page described Table 6A Lines 40 to 42 wrongly.**
  - It called them patients "screened positive" for food, housing and financial insecurity, counted from diagnoses such as the Z59 codes. They are patients **given services** for each need: the lines' value sets list interventions (CPT, HCPCS and SNOMED CT), not diagnoses or screening results.
  - The page now labels them food, housing and financial insecurity services. Like Line 39, each is a share of all patients, not of the patients screened, since a service can be given without a coded screening.
  - It also said a screening counted "not from questionnaire answers alone". A questionnaire answered with its LOINC code does count on Line 39, as the module has read since 0.10.0.
  - Grant figures copied from 0.15.0 for Lines 40 to 42 are counts of services given, whatever the page called them.
- **Fixed: the three UDS fields on the Demographics form shared positions with stock fields.**
  - Housing Status and Agricultural Worker took positions 15 and 16 in the Stats group, the same as Religion and Tribal Affiliations, and Military Service took 17.
  - New installs place them at 17 to 19. Upgrading through Module Manager moves them there too, only where they are still at the module's positions, so a site's own order is kept.
  - Tested by upgrading a site on OpenEMR 8.4.1, then running the install again: 17, 18, 19, with no field moved twice.
- `info.txt` says the module's version. It said 1.0.0 from the first release.
- `FOOTPRINT.md` names the guards `install.sql` actually uses, and describes the field moves.

## 0.15.0 — 2026-10-18

- **New page: Grant evidence** (Reports › UDS Report › Grant evidence). It gives the counts grant applications and reports ask for, from the same patients as the UDS Report, for the Universal Report or a Grant Report population, with a CSV download.
  - **Who we serve:** patients by age band, sex at birth, insurance, income as a share of the poverty guideline, and ZIP code; homeless patients, migratory or seasonal agricultural workers, veterans, and patients best served in a language other than English.
  - **Social needs screening:** Table 6A Lines 39 to 42 (screened, and positive for food, housing and financial insecurity), overall and by ZIP, age band and insurance. They're Table 6A's own counts, so they agree with it. Until the site fetches those lines' value sets, the page says they aren't counted.
  - **Quality measures:** links to Tables 6B and 7 and to eCQM readiness.
- **De-identified:** counts only. A count from 1 to 10 shows as "<11". Within a breakdown, when exactly one count is hidden, the next smallest is hidden too. ZIP codes with fewer than 11 patients are folded into "Other ZIP codes". The README says what this doesn't cover: figures for different populations or years can still be subtracted from each other.
- **Tested** with unit tests for the suppression rule (including the extra hidden cell), each breakdown, the ZIP folding, and Table 6A's patients per line against its own counts. Also run on OpenEMR 8.4.1 with `dev/uds_grants_check.py` in the workspace:
  - the page's total matches the UDS Report's;
  - no count from 1 to 10 appears in the CSV;
  - the three Grant Report populations load.
  - **Social needs:** with synthetic value sets for Lines 39 to 42 and nothing billed, every line shows 0, as the control. After a screening billed for two patients and a food-insecurity diagnosis for one, Lines 39 and 40 show "<11" and Lines 41 and 42 stay at 0.
- No database change.

## 0.14.0 — 2026-10-18

- **eCQM readiness lists records the measures count that OpenEMR treats as deleted or wrong.** For the patients seen at countable visits, a new section counts:
  - problems marked Entered in Error or Refuted (those classified Diagnosis);
  - immunizations marked Entered in error;
  - prescriptions with Currently Active unticked and no end date;
  - observations, care plan entries and orders in forms deleted from the visit;
  - lab results reported as error or cancelled.

  Each row says how to fix the records where there's a way: clear the problem's Classification Type, or give a stopped prescription its end date.
- **Asked of OpenEMR itself.** A row shows only while the installed OpenEMR's eCQM builder still reads those records, judged from the query of the QDM service that reads them. With openemr/openemr#14587, for example, the observations row goes away.
- **The Vitals-form rows go away on an OpenEMR that reads the Vitals form.** OpenEMR master does since 2026-10-18 (openemr/openemr#14656).
- **The HbA1c row names Table 7** for the diabetes measure (CMS122). It said Table 6B Line 15.
- **Tested** on OpenEMR 8.4.1 with `dev/uds_ecqm_check.py` in the workspace.
  - **Counted:** one record of each kind.
  - **Not counted (decoys):**
    - an unclassified problem in error;
    - a stopped prescription with an end date;
    - another patient's deleted form sharing an observation's form id;
    - a final lab result;
    - records of a patient with no 2026 visit.
  - **The engine was asked** on master 44cbd6af98 and on #14587's branch: the observations row is needed on master and not with #14587.
- No database change.

## 0.13.0 — 2026-10-15

- **Table 6A counts vaccines recorded in the Immunizations screen (Lines 24, 24a and 24b).** Until now only vaccines billed on the fee sheet counted.
  - **How they're matched:** by CVX code. Each line's CVX codes are the ones CDC's CPT-to-CVX crosswalk maps from the CPT codes the manual lists for it. Two old CPT codes, 90712 (oral poliovirus) and 90724 (a retired flu code), have no CVX code.
  - **What counts:** a vaccine given here. That means completed or with no status, recorded as a new immunization record or with no source, not refused, and not marked as added in error.
  - **What doesn't:** a historical record of a vaccine given elsewhere, and unspecified-formulation codes such as CVX 88, which are on no line.
- **A record with no encounter now counts at the patient's visit that day.** Before, it always counted as a visit of its own on its date. Now it joins the patient's encounter that day if there is one, choosing one at an in-scope site first. It's a visit of its own only on a day with no encounter.
  - **Why:** a vaccine billed at a visit and also recorded in the Immunizations screen without its encounter counted as two visits.
  - **What else it affects:** the same applies to prescriptions, screenings, Observations, lab results and care plan entries without an encounter. Column A can go down where those were counted twice; Column B (patients) doesn't change.
- **Records to fix:** the patient list no longer tells staff to fix the medication, dental and PrEP problems in Demographics.
- **Tested** on OpenEMR 8.4.1 with nine synthetic patients (`dev/uds_6a_immunization_check.py` in the workspace). Every vaccine was saved through the Immunizations screen.
  - **Counted:** Line 24a twice (the billed visit with its unlinked record counted once, plus a same-day record at a visit with only a diagnosis), Line 24b once (linked to its encounter), and Line 24 once (on a day with no visit).
  - **Not counted:** the refused, historical, added-in-error and unspecified-formulation records, and the patient with only a No Show visit.
  - **Controls:** the released 0.12.0 code counted only the billed vaccine. With the same-day rule taken out, the billed visit counted twice.
- No database change.

## 0.12.0 — 2026-10-15

- **Table 6A's dental lines count only services by dental staff (Lines 27–32).** The manual allows only licensed dentists, dental hygienists and dental therapists, or staff working under their direct supervision, except that fluoride (Line 31) also counts at a medical visit.
  - **Who counts as dental staff:** the visit's provider, placed as Table 5 places them: the line set for the visit category, else the line chosen in Settings, else the line their NUCC taxonomy code suggests. A dental assistant counts, though not a Table 5 provider.
  - **Before upgrading, check the Table 5 provider list.** OpenEMR gives every new user the Family Medicine taxonomy code, so a dentist who still has it is a family physician to this report, and their dental services stop counting. Choose their line in Settings, or correct their taxonomy code.
  - **New record problem:** a dental service on Lines 27–32 that isn't counted, because the visit wasn't with dental staff.
- **Table 6A counts PrEP (Line 21e) only for patients prescribed a PrEP drug, as the manual asks.**
  - **What counts:** a Z29.81 visit of a patient prescribed FTC/TDF, FTC/TAF, cabotegravir or lenacapavir in the year (a prescription started in the year, or earlier with an end date in the year or later), or given one from OpenEMR's inventory. Drugs are found by name, generic or brand (Truvada, Descovy, Apretude, Yeztugo).
  - The line is no longer marked partial.
  - **New record problem:** a Z29.81 visit with no such prescription.
- **Tested** on OpenEMR 8.4.1 with synthetic users, patients, prescriptions and an inventory sale (`dev/uds_6a_staff_check.py` in the workspace):
  - **Dental:** the dentist's and the dental assistant's services counted, and the family physician's fluoride. The physician's oral exam and the restorative service by a user with no taxonomy code didn't, and both patients were listed. Choosing Dentists for that user in Settings made the restorative service count, as a control.
  - **PrEP:** of seven Z29.81 patients, the three with a 2026 prescription, a 2025 prescription ending in 2026, or an inventory sale counted. The four listed were: no prescription, a 2024 prescription with no end date, emtricitabine alone, and a prescription first written in 2027.
  - **Control:** the released 0.11.0 code counted every one of these visits.
- No database change.

## 0.11.0 — 2026-10-15

- **Table 6A reads interventions recorded in care plans.** SDOH interventions are often written in the Care Plan form rather than on the fee sheet.
  - **What counts:** entries marked **Completed** (a service provided, not planned), of any type except goals and health concerns, with SNOMED CT, CPT or HCPCS codes. Each counts at its visit, or as a visit of its own on its date when it has none.
  - **Left out:** entries still Active or Draft, and rows of a deleted Care Plan form.
  - **Lines affected:**
    - Lines 37, 38 and 40–42 now count in full once their value sets are fetched.
    - The SNOMED CT codes the manual lists for Lines 35 and 36 count from care plans as well, fetched or not.
    - A visit counts on every line it matches: one of Line 36's codes is also in Line 42's value set.
  - **Tested** on OpenEMR master with synthetic care plans. Exactly the predicted entries counted: Completed ones, at their visit or on their own date. The Active entry, the goal, and the deleted form's entry didn't count, and un-deleting that form made it count, as a control. The released 0.10.1 code counts none of them.

## 0.10.1 — 2026-10-15

- **New record problem: a tobacco cessation or opioid use disorder medication Table 6A can't count.**
  - **What it catches:** a prescription, or a medication given from inventory, named buprenorphine, methadone, naltrexone, nicotine or varenicline, whose RxNorm code is missing or not in the Line 26c2 or 26c3 value set. Usually it's coded only by ingredient.
  - **The fix:** choose the product (strength and form) from the RxNorm list, so the code is in HRSA's value set.
  - **When it runs:** only once those lines count from fetched value sets.
  - **Wording:** methadone and naltrexone have other uses, so the row asks staff to check each one. Bupropion is left out, since it's mostly prescribed as an antidepressant.
  - **Tested:** on OpenEMR master with synthetic records. It flagged exactly the ingredient-only, uncoded and uncoded-dispensed records, and nothing in the value set, no bupropion, and no patient without a countable visit.

## 0.10.0 — 2026-10-15

- **Table 6A counts the lines the manual defines by value sets, from codes each site fetches with its own UMLS API key.** The module doesn't ship the codes: the UMLS licence doesn't allow passing CPT codes on (category 3), and its SNOMED CT terms require sub-licences and access controls a public repository can't give.
  - **Fetching.** A new page (Settings › Table 6A value sets, `admin/super`), or `bin/uds-value-sets.php`, fetches the 21 value sets the 2026 manual names from VSAC's FHIR API. The key is used for the fetch and not saved. The page shows each set's version and fetch date, and the licence notices for the code systems fetched.
  - **What counts once all of a line's value sets are fetched:**
    - Lines 26c2, 26c3 and 38–42 are counted.
    - Lines 1–9, 21, 26f and 37 count the value sets' codes as well as the listed ones.
    - Medications come from prescriptions and inventory dispensing (RxNorm).
    - Screenings and tests come from SDOH assessments, questionnaires, Observations and lab results (LOINC).
    - Everything else comes from the fee sheet.
  - **The manual's exclusions still hold.** Line 9's value set includes gestational diabetes (O24.4-), which the manual excludes from Line 9, so it still isn't counted. Line 3's value set adds Z86.11 (personal history of tuberculosis), which counts.
  - **What isn't counted yet:** medications coded only by ingredient, and SNOMED CT interventions recorded only in a care plan or a note. The report says so line by line.
- **Report and CSV:** both say how many value sets are fetched, and when.
- **Upgrade:** new tables `uds_value_set` and `uds_value_set_code`, created by Module Manager's Upgrade (`sql/0_9_0-to-0_10_0_upgrade.sql`, run for 0.8.0 and 0.9.0).
- **Outbound network:** the module now makes HTTPS requests to cts.nlm.nih.gov, only when an administrator fetches the value sets.
- README: a Code systems section with the CPT, CDT and SNOMED CT notices for the codes `data/table-6a.json` copies from the manual.

## 0.9.0 — 2026-10-13

- **The eCQM readiness page counts more records the measures can't see.** No part of OpenEMR's eCQM builder reads the Vitals form, History or the PHQ-9 form, so a clinic using those everyday screens reports near zero on several Table 6B lines. New rows:
  - patients whose BMI is only in the Vitals form (CMS69 Line 13, CMS155 Line 12);
  - patients whose tobacco use is only in History (CMS138, Line 14a);
  - patients with a PHQ-9 form and no depression screening the measure reads (CMS2, Line 21), when the PHQ-9 form is installed;
  - for each measure's screening or exam codes (tobacco, depression and falls screening, BMI, BMI percentile, blood pressure), patients who have one in the year only as a lab result or as an Observation of another type.

  Each row says what the measure reads instead.
- **Table 9D: an adjustment reason starting with "Sliding" now counts as a sliding fee discount (Column D).** OpenEMR's stock Adjustment Reasons list has no sliding fee entry, so a clinic adds its own, such as "Sliding fee" or "Sliding fee discount". Until now the worksheet listed those for review. Today only Fees › Posting Payments records a reason with an adjustment; [openemr/openemr#14583](https://github.com/openemr/openemr/pull/14583) (not yet merged) adds one to the checkout discount.

## 0.8.0 — 2026-10-13

Found by running the module on realistic synthetic records (40 Synthea patients imported into OpenEMR 7.0.3, upgraded to master): on those, Tables 6B and 7 couldn't be calculated at all.

- **A patient OpenEMR's eCQM engine refuses no longer stops the calculation.** The engine refuses a patient for every measure over one lab result whose unit isn't UCUM (`mEq/L`, `x10E3/uL`, `IU/L`, or the `UNK` OpenEMR's C-CDA import stores), and it refuses the whole batch with them. A refused batch is now tried again one patient at a time; everyone else is calculated, and the patients it still refuses are kept with the engine's reason. The Tables 6B and 7 page names them (left out of every line until their records are corrected), and the CSV gives their number and reasons, without identifiers. Any other error still stops the calculation. On the Synthea records: 14 of 31 patients refused (`Invalid UCUM unit: 'UNK'`), the other 17 calculated.
- **The eCQM readiness page warns before the calculation:**
  - patients with a lab result in a unit the engine refuses, each unit with the UCUM unit meaning the same where there is one (`mEq/L` is `meq/L`);
  - when this OpenEMR reads lab results as whole numbers (asked of OpenEMR itself), the patients with an HbA1c between 9.0 and 10.0 in the year, whom CMS122 (Line 15) can't count as poorly controlled.
- The readiness page no longer says the module doesn't calculate Tables 6B and 7.
- New column `uds_quality_run.refused`: Module Manager's Upgrade (`sql/0_7_2-to-0_8_0_upgrade.sql`, run for 0.7.0, 0.7.1 and 0.7.2).

## 0.7.2 — 2026-10-13

- **Tables 6B and 7 work on OpenEMR master as it is**, with no change to the module: the 2026 measures merged on 2026-10-13 (openemr/openemr#14536). Rechecked on master 76fa2f1e88, which also retired the Node calculation service (#14524; the module never used it): `bin/uds-quality.php` calculated 2026 for nine synthetic patients with 15 measures, CMS164v7 reported missing, and each patient's CMS165v14 result matched its prediction (denominator 5, numerator 2). Ongoing problems and visits with no end time still don't count until openemr/openemr#14526 is fixed.
- README: the requirements and the compatibility table say so.

## 0.7.1 — 2026-10-12

- **Runs on OpenEMR 8.1.0 to 8.3.0**, not only 8.4 and later. Those releases don't have OpenEMR's `CurrentRequest` (added in 8.4.0), so the module reads the request itself there. Checked on the official 8.1.0, 8.2.0, 8.3.0 and 8.4.0 images: the module installs through Module Manager and every page loads. Tables 6B and 7 still need OpenEMR's PHP eCQM engine (master after 8.4).
- A compatibility table in the README: what works on each OpenEMR release, and how each was checked.

## 0.7.0 — 2026-10-12

- **Tables 6B and 7, the eCQM lines**, on their own page from the UDS Report, with a CSV download. Calculated by OpenEMR's PHP eCQM engine with the measure versions the 2026 manual names.
  - The manual's column logic: Column A is the denominator less exclusions and less exceptions who don't meet the numerator; Column B equals A; Column C is the numerator; each patient counts once; only patients with a countable UDS visit count.
  - Line 12 needs all three of CMS155's numerators; Line 14a is CMS138's third population; Lines 23a and 23b are CMS137's initiation and engagement over one denominator (read from each measure's CQL).
  - Table 7's blood pressure and diabetes sections by race and ethnicity, from each patient's Table 3B cell by Appendix B's crosswalk, in the manual's line order, with subtotals.
  - Measures not installed at the manual's version are named, not guessed at; so are the lines with no eCQM.
- **The calculation is stored and resumable.** An administrator starts it from the page (50 patients per request) or with `bin/uds-quality.php` on the server; each batch is saved with its progress in one transaction. New tables `uds_quality_run` and `uds_quality_result`, also in `sql/0_6_0-to-0_7_0_upgrade.sql` for Module Manager's upgrade.
- Needs OpenEMR's PHP eCQM engine (master after 8.4) and the 2026 measures (openemr/openemr#14536, not yet merged).
- **Upgrading from 0.6.0:** run the database upgrade (Modules › Manage Modules › Upgrade next to UDS Report). Until then the new page says so instead of failing. Checked: Module Manager's upgrade creates both tables and records 0.7.0.
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

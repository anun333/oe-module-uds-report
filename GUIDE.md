# UDS Report: staff guide

For the staff who use the module day to day, task by task. What it counts and why, and how to install it, are in the [README](README.md).

The module builds the tables; it doesn't submit them. You enter the figures in HRSA's EHBs, after checking them against your own records. It has been tested only on made-up patients, not yet against a real submission.

## The person who prepares the UDS report

### Run the UDS report for a year

*Where:* the Reports menu › **UDS Report**.

1. Choose the year in **Calendar year**, then **Show**. The same goes for the year on the eCQM readiness, Tables 6B and 7 and Table 9D pages.
2. Leave **Report** on **Universal Report**, unless you are preparing a Grant Report.
3. Read the banner. **Table totals agree** means every table counts the same patients. **The table totals do not agree yet.** means some patients are missing from a table: fix the **Blocking** records (next task) and reload.
4. Choose **Download CSV** to save the tables.

*What you see:* **Patients by ZIP Code**, then Tables 3A, 3B, 4, 5 and 6A, in the manual's layout. Under the ZIP table, the fewest ZIP codes where 75% of patients live, and any of them missing from your Form 5B once it is entered in Settings.

*What it doesn't do:*
- No Tables 8A or 9E, and not the two forms.
- Table 5's FTE column is blank: FTEs come from HR records.
- It doesn't judge each visit: what counts is set by visit category and provider in Settings.
- It doesn't change your scope. A ZIP code missing from Form 5B is a change in scope to request.

### Work the Records to fix list

*Where:* the UDS Report, under **Records to fix before submitting**.

Each line is a problem and how many patients have it. **Blocking** problems keep a patient off a table, so fix those first. **Quality** problems put a patient on an "unknown" line.

1. Click the count to list the patients.
2. Click a name to open the chart, and fix the record as the line says, usually in Demographics.
3. Reload the report.

Two fixes catch people out. Type the Financial Review Date as year-month-day (2026-09-15): typed any other way, OpenEMR silently saves no date. Enter monthly income as one amount (2000 or $2,000.50), not a range or words.

*What it doesn't do:* it never changes a record. Opening a list is written to OpenEMR's audit log. Housing and farmworker status are read as they are now, with no history, so the manual's look-back periods aren't applied.

### Answer HRSA's checks before you submit

*Where:* **HRSA checks** on the UDS Report.

1. Choose the year and the report, then **Show**.
2. Read each line under **What HRSA is likely to ask about**: the figures it quotes, HRSA's edit number, how many health centers HRSA raised it for in 2025, and how often the check agreed with HRSA.
3. For each one, correct the records behind it, or write down why the figure is right. HRSA asks for that explanation in EHBs.
4. Look down the comparison with the year before, under its own heading: the cells that changed by more than a quarter and by at least 50 since the report HRSA published. Reviewers ask about changes that size.

*What you see:* counts only, never patients. Checks that need last year's report run only once an administrator has entered the HRSA grant or look-alike number in Settings.

*Who sees what:* the Table 9D checks (revenue by payer, charges per visit) appear only for staff who can see the Collections report. They read the Table 9D worksheet, so check what they raise against the Table 9D you'll file.

*What it doesn't do:* HRSA runs many more checks than these, on tables the module doesn't compute (staffing, costs, grants, births). A check not raised here can still be raised in EHBs. HRSA doesn't publish its checks; these are rebuilt from the messages it published with the 2025 data. A count from 1 to 15 is hidden in HRSA's published file, so those cells aren't compared.

### Run a Grant Report

*Where:* the **Report** list on the UDS Report.

Choose 330(g), 330(h) or 330(i), then **Show**. The page and its CSV then cover only that population, with all their visits and services.

*What it doesn't do:* no ZIP code table, FTEs, Tables 6B and 7, or Table 9D. The **eCQM readiness**, **Tables 6B and 7** and **Table 9D worksheet** buttons show only on the Universal Report.

### Get counts for a grant application

*Where:* **Grant evidence** on the UDS Report.

1. Choose the year, and the population in the second list, then **Show**.
2. Read **Who we serve**, **Special populations and language**, **Social needs: screening and services** and **Quality measures**, or **Download CSV**.

*What you see:* counts and shares only, no names or dates. A count from 1 to 10 shows as <11, and one more is hidden where a total would give it away.

*What it doesn't do:* hiding works one table at a time, so figures for different populations or years can still be subtracted to reveal a small count; check what you publish together. Social needs lines stay empty until the value sets are fetched.

## Front desk and registration

### Record the UDS fields at registration

*Where:* the patient's Demographics, Stats section.

- **Housing Status (UDS)**: where the patient stayed last night.
- **Agricultural Worker (UDS)**: whether their main work in the last 24 months was farm work, and which kind.
- **Military Service (UDS)**: ask "Have you served in the U.S. military, armed forces, or uniformed services?"

If the patient won't say, choose "Chose not to answer"; an empty field lands on the Records to fix list. Income and family size count only when the Financial Review Date is within the 12 months before the last visit, so review them yearly.

*What it doesn't do:* it never reads OpenEMR's free-text "Homeless" and "Migrant/Seasonal" fields. Records to fix points out when one is filled and the UDS field isn't.

## Clinicians and coders

### Code services so Table 6A counts them

*Where:* the visit's fee sheet; for some lines, the care plan or prescription.

- Table 6A counts the billing codes on each visit. Code a free service with a zero charge.
- Support and social-needs services (Lines 35 to 42) also count from care plan entries marked Completed, not Active or Draft.
- For tobacco-cessation and opioid use disorder medicines, choose the product (strength and form), not just the ingredient.
- PrEP (Z29.81) counts only with a PrEP prescription in the year.
- Dental lines count only when the provider has a dental Table 5 line. Fluoride counts at a medical visit too.

*What it doesn't do:* it can't see a service written only in a note or a scanned document. A line marked * counts in part; the list under Table 6A says what each leaves out.

### Fix what the quality measures can't see

*Where:* UDS Report › **eCQM readiness**.

1. Check **Measures**: whether each measure UDS uses is installed.
2. Work down **Records the measures cannot see**, using each row's **Why, and how to fix**. Typical fixes: an Encounter Type with a code on every visit; problems classified as Diagnosis, with a code; blood pressure, BMI, tobacco and depression screens recorded where the measure reads them; lab results in UCUM units.
3. Read **Records the measures count that OpenEMR treats as deleted or wrong**.

*What it doesn't do:* it doesn't change records. Don't add end dates to ongoing problems to make them count: an end date marks a problem resolved.

## Billing and finance

### Prepare the Table 9D worksheet

*Where:* UDS Report › **Table 9D worksheet**, for staff who can open OpenEMR's Collections report.

1. Read the notes at the top. Ask an administrator to map any unmapped insurers in Settings. Decide each adjustment reason with no rule: Column D, bad debt, or a correction.
2. Check **Table 9D: Accrued Patient Service Revenue** and **Adjustments by reason**. **How the figures are worked out** explains each figure.
3. Choose **Download CSV**.

*What it doesn't do:* it is a worksheet, not Table 9D, which follows your audited financial statements. Capitation, settlements, wrap payments, incentive and contract pharmacy revenue come from your general ledger. Bad debt is by direct write-off only.

## Administrators

### Set up the report

*Where:* UDS Report › **Settings**. Review it each year.

- **Visit categories that are countable visits**: untick categories used only for labs, injections, screenings or outreach.
- **Visit categories that are virtual visits (Table 5, Column B2)**: tick your telehealth categories.
- **Sites**: tick **In scope of project**, **School-based site** and **In or next to public housing**.
- **Poverty guidelines**: the region where the health center is.
- **Service area ZIP codes (Form 5B)**: from your Form 5B.
- **HRSA grant or look-alike number**: H80CS followed by five digits, or LALCS for a look-alike. The page says whether it's found in the data HRSA published.
- **Insurance companies**: override **UDS category used** where it's wrong, such as a CHIP plan.
- **Table 5 lines**: check each person's **Line used**; anyone still on OpenEMR's default Family Medicine code is highlighted. Choose **Not a provider (encounters not counted)** for medical assistants and LPNs.

Then choose **Save**.

*What it doesn't do:* it can't tell Medicaid from CHIP. Set CHIP plans here, or they count as Medicaid.

### Fetch the Table 6A value sets

*Where:* **Settings** › **Table 6A value sets (fetched with your UMLS API key)**, or **Fetch value sets** on the report.

Enter the site's **UMLS API key** (a free National Library of Medicine account) and choose **Fetch from VSAC**. Do it again each year, when HRSA publishes a new manual.

*What it doesn't do:* it doesn't keep the key. Until the fetch, lines defined by value sets are blank, or count only the codes the manual lists.

### Calculate Tables 6B and 7

*Where:* UDS Report › **Tables 6B and 7**. Anyone who can open the report can view it; only administrators calculate.

1. Work through eCQM readiness first.
2. Choose **Calculate**, or **Calculate again** after records change. Keep the page open; if it closes, **Resume**. For a large health center, run it on the server (see the README).
3. Read **Table 6B: Quality of Care Measures** and Table 7, or **Download CSV**.

If the page names patients the engine refused, fix their records (usually a lab unit) and calculate again.

*What it doesn't do:*
- Results don't follow record changes until you calculate again.
- It needs OpenEMR's PHP eCQM engine (after 8.4); without it, the page says so.
- Not calculated: prenatal care, HIV linkage to care, dental sealants, Line 18, and Table 7 Section A. Report those from your records.

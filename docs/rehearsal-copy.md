# Run your UDS report on a rehearsal copy of an older OpenEMR

For a health center on OpenEMR 7.0.x or 8.0.x. This module needs OpenEMR 8.1 or later, so you try it on a copy of your system upgraded to 8.5, and your live OpenEMR stays as it is until you decide to upgrade it. No patient data leaves your site.

The steps, in order:

1. Export your layouts and lists (no patient data) and have them checked.
2. Rehearse the upgrade on a copy of your database.
3. Install the module on the copy.
4. Enter your settings.
5. Run last year's report and compare it with what you filed.
6. In January, do it again on the full year.

Checked end to end on 2026-11-01 with made-up patients, from the official OpenEMR 7.0.3 (patch 4) Docker image to 8.5.0.

## 1. Export your layouts and lists

This file holds your forms' layouts and your lists (race, ethnicity, language and the rest), not patients. OpenEMR 7.0 to 8.2 can write it; 8.3.0 removed the Backup page.

1. **Administration › Config › Features:** tick **Configuration Export/Import** and **Save**. It is off by default.
2. **Administration › System › Backup:** choose **Export Configuration**.
3. Click in the **Lists** box and press Ctrl+A to select every list. Do the same in the **Layouts** box. Tick **Include all lists referenced in chosen layouts**.
4. **Continue.** Your browser saves `openemr_config.sql` (about 2 MB).

You can open the file in a text editor to see what's in it. Whoever checks it runs this module's checker, which reads the file as data and refuses one holding anything but layouts and lists:

```
git clone https://github.com/anun333/oe-module-uds-report && cd oe-module-uds-report && composer install
php tools/setup-check.php openemr_config.sql
```

It reports, from the file alone:
- the Demographics fields the module reads, and whether each is there and switched on;
- whether each race and ethnicity entry has the CDC code the module rolls up to Table 3B (an entry without one counts as race not reported);
- which languages count toward limited English proficiency;
- fields on other forms that record what the UDS asks (income, household size, housing, veteran status) where the module doesn't read them.

The file can't show your insurance companies, sites or visit categories. Those are checked on the copy, in step 4.

## 2. Rehearse the upgrade on a copy

Use [openemr-reference](https://github.com/anun333/openemr-reference)'s rehearsal (`openemr-rehearse.sh`). It runs the upgrade on a copy of your whole site in Docker and never touches your live system. It checks the copy three ways: receipts of the copy before and after the upgrade must match (counts and money totals, no patient data), the install doctor must find nothing, and OpenEMR itself must read every patient and document back. Its guide, [Upgrading OpenEMR without losing anything](https://github.com/anun333/openemr-reference/blob/main/UPGRADE-GUIDE.md), has every step, and the live upgrade after it.

1. On your server, copy the database and the `sites/` folder (documents and the keys that unlock them):

   ```
   mysqldump --single-transaction --routines --triggers openemr > site.sql
   tar -czf sites.tar.gz -C /var/www/html/openemr sites
   ```

2. On a machine with Docker, inside your network (the rehearsal says how much room it needs):

   ```
   openemr-rehearse.sh --dump=site.sql --sites=sites.tar.gz --from=7.0.3 --to=8.5.0 --keep
   ```

   Use your own version for `--from` (Administration › About). It takes from ten minutes to an hour and ends with a verdict. `--keep` leaves the upgraded copy running and prints two commands: one that gives its address (`127.0.0.1` and a port), and one that deletes it.
3. Open the address and log in with your live system's own username and password.

The copy holds your patients' records, and so do `site.sql` and `sites.tar.gz`. The copy answers only on that machine (`127.0.0.1`); delete all three when you're done. If the container restarts, its port changes: run the first printed command again.

## 3. Install the module on the copy

From the machine running the copy (the container's name is in the rehearsal's output: the project name, then `-openemr-1`):

```
docker exec CONTAINER sh -c 'cd /var/www/localhost/htdocs/openemr && composer require --update-no-dev anun333/oe-module-uds-report'
```

Then in OpenEMR, **Modules › Manage Modules:** **Register**, **Install** and **Enable** UDS Report. It adds **UDS Report** to the Reports menu, and three fields to Demographics (Housing Status, Agricultural Worker and Military Service, all marked UDS).

## 4. Enter your settings

**Reports › UDS Report › Settings:**

- **HRSA grant or look-alike number.** The page says whether HRSA's 2025 data has it.
- **Insurance companies:** the UDS category of each. One the module can't decide is a blocking problem until you choose. OpenEMR's default claim type, Other HCFA, decides nothing. A national or government plan such as FSM MiCare is **Other Public Insurance (Non-CHIP)**.
- **Visit categories that are countable visits**, and under **Sites**, which are in your scope of project, school-based, or in or next to public housing.
- **Poverty guidelines:** the manual tells health centers outside Alaska and Hawaii, the territories included, to use the 48 contiguous states' guidelines, which is the default. HHS publishes no guidelines for the Freely Associated States. If you're unsure which to use, ask the UDS Support Center.

## 5. Run last year's report and compare

1. **Reports › UDS Report:** choose **Calendar year** 2025, then **Show**.
2. Work the **Records to fix** list, blocking problems first. On a copy you can fix records to see the effect, but the fixes stay on the copy: make them on your live system too.
3. **HRSA checks:** for 2025, the page sets your figures beside the report you filed, as HRSA published it, and lists every cell that differs. HRSA hides counts from 1 to 15 and withholds some tables (Table 5 for about half of health centers), so those cells aren't compared.
4. **Download CSV** on either page keeps the figures.

Expect differences. Records change after a report is filed, HRSA's reviewers may have asked for corrections, and the module counts by rules you set (visit categories, insurers). Each difference is a question to answer before you rely on the module for 2026.

## 6. In January, the full year

After December 31, repeat steps 2 to 5 with a new dump: a new copy, the module installed, your settings entered again (they live in the copy's database), and calendar year 2026. HRSA checks then compares 2026 with your published 2025 report and runs HRSA's year-to-year checks. The 2026 report is due February 15, 2027.

When you upgrade your live system (the guide's step 4: it takes a receipt before and after, and puts everything back by itself if they differ), install the module there the same way and enter the settings once more.

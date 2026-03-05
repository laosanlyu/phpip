# Task Rules Audit

This document summarises the pre-defined task rules in phpIP, identifies gaps against real-world IP procedural deadlines, and provides reference links to official sources.

> **Last audited:** March 2026
> **Source:** `database/seeders/TaskRulesTableSeeder.php` (88 rules defined)
> **Live DB:** 57 rules loaded (some seeder rules may not have been inserted)

---

## Table of Contents

- [How Rules Work](#how-rules-work)
- [Existing Rules by Category](#existing-rules-by-category)
  - [PAT — Patents](#pat--patents)
  - [TM — Trademarks](#tm--trademarks)
  - [OP — Opposition](#op--opposition)
  - [DSG — Design](#dsg--design)
- [Renewal Configuration (country table)](#renewal-configuration-country-table)
- [Gap Analysis](#gap-analysis)
  - [Critical Issues](#critical-issues)
  - [Important Gaps](#important-gaps)
  - [Minor Issues](#minor-issues)
- [Missing Country Coverage](#missing-country-coverage)
- [Recommendations](#recommendations)
- [Reference Links](#reference-links)

---

## How Rules Work

Task rules are **templates** stored in the `task_rules` table. They are **not** executed directly — instead, a MySQL **trigger** (`event_after_insert`) fires every time an event is inserted on a matter. The trigger reads the rules table, finds matching rules, and creates task rows automatically.

```
Event inserted → MySQL trigger fires → Reads task_rules → Creates task rows
```

For recurring renewals specifically, the trigger calls a stored **procedure** (`insert_recurring_renewals`) that loops through years and creates individual renewal task rows based on the `country` table's `renewal_first`, `renewal_base`, and `renewal_start` columns.

### Rule Matching Logic

A rule matches when ALL of these are true:
- `trigger_event` = the event code being inserted
- `for_category` = the matter's category (PAT, TM, etc.)
- `for_country` matches the matter's country (or NULL = generic fallback)
- `for_origin` matches the matter's origin (or NULL = generic fallback)
- `for_type` matches the matter's type (or NULL = generic fallback)
- `active = 1`
- No `abort_on` event exists on the matter
- `condition_event` exists on the matter (if specified)

**Country-specific override:** If a rule exists with `for_country='US'`, it takes precedence over the `for_country=NULL` generic rule for the same task+trigger combination.

---

## Existing Rules by Category

### PAT — Patents

#### Expiry (EXP)

| ID | Trigger | Country | Type | Offset | Description |
|----|---------|---------|------|--------|-------------|
| 8 | FIL | any | PRO | +12 months | Provisional patent expires 1 year after filing |
| 12 | FIL | any | any | +20 years | Standard patent term from filing date |

#### Priority Deadline (PRID)

| ID | Trigger | Country | Offset | Behaviour | Description |
|----|---------|---------|--------|-----------|-------------|
| 1 | FIL | any | +12 months | use_priority=1 | Paris Convention priority deadline |
| 25 | PRI | any | — | delete_task=1, condition=FIL | Delete PRID when priority is claimed |

#### File By (FBY)

| ID | Trigger | Country | Offset | Behaviour | Description |
|----|---------|---------|--------|-----------|-------------|
| 21 | PRI | any | +12 months | abort_on=FIL | File from priority date, aborts when filed |
| 29 | FIL | any | — | clear_task=1 | Clears FBY when filing occurs |

#### Draft By (DBY)

| ID | Trigger | Offset | Behaviour | Description |
|----|---------|--------|-----------|-------------|
| 5 | DRA | +0 | clear_task=1 | Clears DBY when draft is completed |
| 24 | REC | +2 months | — | Draft due 2 months after receiving instructions |

#### National Phase / Entry (NPH, ENT)

| ID | Task | Trigger | Country | Offset | Description |
|----|------|---------|---------|--------|-------------|
| 22 | NPH | FIL | WO | +30 months (use_priority) | PCT 30-month national phase deadline |
| 34 | ENT | FIL | WO | +31 months (use_priority) | National phase entry deadline |

#### Request Examination (REQ)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 7 | EP | PUB | +6 months | EP: after publication of search report |
| 6 | JP | FIL | +3 years | Japan: 3 years from filing |
| 31 | US | FIL | +36 months | US: see [issue note](#4-duplicate-us-req-rules) |
| 35 | US | FIL | +24 months | US: see [issue note](#4-duplicate-us-req-rules) |
| 53 | CA | FIL | +42 months | Canada: see [issue note](#9-ca-examination-request-deadline) |
| 54 | GB | FIL | +12 months | UK: combined search & examination |
| 55 | DE | FIL | +84 months (7y) | Germany |
| 70 | FR | FIL | +18 months | France |
| 23 | WO | FIL | +22 months (use_priority) | PCT Chapter I |
| 1302 | CN | FIL | +36 months | China |

#### Respond (REP)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 10 | generic | EXA | +3 months | Generic office action response |
| 11 | EP | EXA | +4 months | EP office action |
| 13 | EP | ALL | +4 months | EP R71(3) intention to grant |
| 18 | EP | PUB | +6 months | EP written opinion |
| 47 | EP | ALL | +6 months | EP R70(2) |
| 37 | US | EXA | +4 months | US non-final office action |
| 60 | US | EXA | +6 months | US office action (extended) |
| 63 | US | EXAF | +3 months | US final office action |
| 46 | US | REST | +6 months | US restriction requirement |
| 41 | US | ALL | +2 months | US notice of allowance |
| 49 | US | REJF | +2 months | US final rejection → appeal brief |
| 9 | FR | SR | +3 months | French search report response |
| 61 | CA | EXA | +6 months | Canada office action |
| 64 | CA | EXAF | +4 months | Canada final OA |
| 62 | GB | EXA | +4 months | UK office action |
| 1311 | CN | EXA | +4 months | China office action |
| 1315 | DE | EXA | +4 months | Germany office action |
| 237 | FR | GRT | +3 months | France working report |
| 235 | DE | PUB | +18 months | DE: written opinion after pub |
| 1321 | WO | IPER | +1 month | PCT international preliminary report |
| 1322 | EP | REJF | +4 months | EP rejection → appeal |
| 1303 | CN | REJF | +3 months | China rejection → appeal |
| 1326 | CA | REJF | +4 months | Canada rejection → appeal |

#### Pay (PAY)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 14 | EP | ALL | +4 months | EP grant fee |
| 19 | EP | PUB | +6 months | EP designation fees |
| 39 | US | ALL | +4 months | US issue fee |
| 44 | US | FIL | +4 months | US filing fee |
| 56 | GB | ALL | +3 months | UK grant fee |
| 58 | CA | ENT | +12 months | Canada entry fee |
| 66 | CA | ALL | +4 months | Canada issue fee |
| 234 | DE | ALL | +3 months | Germany grant fee |
| 236 | FR | ALL | +3 months | France grant fee |
| 36 | HK | GRT | +6 months | Hong Kong grant fee |
| 68 | WO | PUB | +30 months (use_priority) | PCT designation fees |
| 1282 | FR | ENT | +24 months | France national phase entry fee |
| 1323 | US | REJF | +6 months | US RCE fee after final rejection |

#### Produce (PROD)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 15 | EP | ALL | +4 months | EP claim translation for grant |
| 20 | US | PRI | +12 months | US declaration & assignment |
| 30 | US | FIL | +2 months | US IDS |
| 57 | generic | FIL | +16 months (use_priority) | Priority documents |
| 32 | WO | WO | +3 months (use_priority) | Chapter 2 demand |
| 1301 | CN | PRI | +12 months | China declaration & assignment |
| 1316 | CN | ENT | +2 months | China POA after entry |

#### Validate (VAL)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 16 | EP | GRT | +3 months | Validate EP patent in designated states |

#### Extend to HK (EHK)

| ID | Country | Trigger | Offset | Description |
|----|---------|---------|--------|-------------|
| 26 | CN | PUB | +6 months | Extend Chinese patent to Hong Kong |

#### Renewals (REN)

| ID | Trigger | Category | Country | Offset | Recurring | Description |
|----|---------|----------|---------|--------|-----------|-------------|
| 80 | FIL | PAT | any | +3y +12m | Yes | Annual patent renewal (generic) |

### TM — Trademarks

| ID | Task | Trigger | Country | Offset | Behaviour | Description |
|----|------|---------|---------|--------|-----------|-------------|
| 2 | PRID | FIL | any | +6 months | — | TM priority deadline |
| 38 | PRID | PRI | any | — | clear_task=1 | Clear PRID when claimed |
| 1307 | PRID | PRI | EP | — | delete_task=1, cond=FIL | Delete PRID for EUTM |
| 81 | REN | FIL | any | +10 years | recurring=1 | TM renewal every 10 years |
| 242 | REP | REN | US | +6 months | — | US declaration of use at renewal |
| 1310 | REP | EXA | EP | +2 months | — | EUIPO office action response |
| 1327 | DBY | DRA | any | — | clear_task=1 | Clear DBY for TM |
| 1328 | PROD | FIL | any | +1 month | — | CompuMark analysis |
| 1329 | PROD | FIL | any | +2 months | — | Products & services |

### OP — Opposition

| ID | Task | Trigger | Country | Offset | Description |
|----|------|---------|---------|--------|-------------|
| 27 | FOP | GRT | EP | +9 months | EP post-grant opposition |
| 238 | FOP | PUB | EP | +9 months | EP post-publication opposition |
| 239 | FOP | PUB | DE | +9 months | Germany opposition |
| 240 | FOP | PUB | FR | +9 months | France opposition |
| 52 | REP | SUO | EP | +2 months | EP summons to oral proceedings |
| 1280 | REP | SUO | GB | +2 months | UK opposition response |
| 1300 | REP | ORI | US | +2 months | US opposition response |

### DSG — Design

| ID | Task | Trigger | Country | Offset | Behaviour | Description |
|----|------|---------|---------|--------|-----------|-------------|
| 1290 | PROD | FIL | FR | +3 months | — | Soleau envelope production |
| 1291 | EXP | FIL | FR | +5 years | — | French design expiry |
| 1306 | PRID | PRI | any | — | delete_task=1, cond=FIL | Delete priority deadline |

---

## Renewal Configuration (country table)

The `country` table controls how the `insert_recurring_renewals` procedure generates renewal tasks:

| Country | renewal_first | renewal_base | renewal_start | Real-world renewal model |
|---------|---------------|-------------|---------------|-------------------------|
| CA | 2 | FIL | FIL | Annual from year 2 (from filing) ✅ |
| CN | 2 | FIL | GRT | Annual from filing, but only due after grant ⚠️ |
| DE | 3 | FIL | FIL | Annual from year 3 (from filing) ✅ |
| EP | 3 | FIL | FIL | Annual from year 3 (from filing) ✅ |
| FR | 2 | FIL | FIL | Annual from year 2 (from filing) ✅ |
| GB | 5 | FIL | FIL | Annual from year 5 (from filing) ✅ |
| JP | 4 | GRT | GRT | Annual from grant ⚠️ trigger mismatch |
| US | 4 | FIL | FIL | **WRONG** — US uses 3 maintenance fees from grant 🔴 |
| WO | 2 | FIL | FIL | N/A — PCT applications have no renewals directly |

---

## Gap Analysis

### Critical Issues

#### 1. US Maintenance Fees — Wrong Renewal Model 🔴

**Current behaviour:** Rule 80 (generic, trigger=FIL, recurring=1) applies to US. Combined with the country table (`renewal_start=FIL`), this generates **annual** renewal tasks from year 4 of filing — identical to European-style annuities.

**Reality:** US patents have exactly **3 non-recurring maintenance fee windows** calculated from the **grant date**:

| Window | Due between | Grace period |
|--------|-------------|-------------|
| 1st | 3–3.5 years after grant | 6-month grace (with surcharge) |
| 2nd | 7–7.5 years after grant | 6-month grace (with surcharge) |
| 3rd | 11–11.5 years after grant | 6-month grace (with surcharge) |

**Fix needed:**
1. Add 3 non-recurring rules: `task=REN`, `trigger_event=GRT`, `for_country=US`, `for_category=PAT`, `recurring=0`
   - Rule A: +3 years +6 months (1st maintenance fee)
   - Rule B: +7 years +6 months (2nd maintenance fee)
   - Rule C: +11 years +6 months (3rd maintenance fee)
2. Change the US row in the `country` table: set `renewal_start` to something other than `FIL` (e.g., `GRT`) so the generic recurring rule 80 is skipped for US matters

**Reference:**
- [USPTO — Maintenance Fees](https://www.uspto.gov/patents/maintain/maintain-your-patent)
- [37 CFR 1.362 — Time for payment of maintenance fees](https://www.ecfr.gov/current/title-37/chapter-I/subchapter-A/part-1/subpart-C/subject-group-ECFR89d635ae1e5b8da/section-1.362)
- [MPEP 2504 — Patents Subject to Maintenance Fees](https://www.uspto.gov/web/offices/pac/mpep/s2504.html)

#### 2. JP Renewals — Trigger Event Mismatch 🔴

**Current behaviour:** Rule 80 fires on `trigger_event=FIL`. The trigger checks whether `country.renewal_start` matches the event code. Japan has `renewal_start=GRT`. When FIL is inserted, the trigger skips renewal generation because `GRT ≠ FIL`. When GRT is later inserted, no rule exists with `trigger_event=GRT` for REN. **Result: JP patent matters get zero renewal tasks.**

**Reality:** Japanese patent annuities are due annually from the **grant date**, starting from year 1 (paid in advance for years 1-3 at grant). Years 4+ are paid individually.

**Fix needed:** Add a rule: `task=REN`, `trigger_event=GRT`, `for_country=JP`, `for_category=PAT`, `recurring=1`

**Reference:**
- [JPO — Annuity fees](https://www.jpo.go.jp/e/tetuzuki/ryoukin/shutugan.html)
- [WIPO Japan — Patent Fees](https://www.wipo.int/patents/en/fees/jp.html)

#### 3. CN Renewals — Same Trigger Mismatch 🔴

**Current behaviour:** Same issue as JP. China has `renewal_start=GRT` in the country table. Rule 80 triggers on FIL, so the renewal procedure skips CN matters. **CN patent matters get zero renewal tasks.**

**Reality:** Chinese patent annuities are calculated from the **filing date** but only become due after **grant**. The first annuity covers from filing to grant, and is due within 2 months of the grant notification. Subsequent annuities are due annually on the filing anniversary.

**Fix needed:** Add a rule: `task=REN`, `trigger_event=GRT`, `for_country=CN`, `for_category=PAT`, `recurring=1`

**Reference:**
- [CNIPA — Patent Fee Schedule](https://english.cnipa.gov.cn/col/col3060/index.html)
- [WIPO China — Patent Fees](https://www.wipo.int/patents/en/fees/cn.html)

### Important Gaps

#### 4. Duplicate US REQ Rules

**Issue:** Two rules create REQ tasks for US patents triggered by FIL:
- Rule 31: +36 months
- Rule 35: +24 months

The US does not have a formal "request for examination" — examination begins automatically after filing. These may represent other deadlines (e.g., IDS duty, small entity assertion), but having two rules for the same task+trigger+country combination means both will fire, creating duplicate tasks.

**Reference:**
- [MPEP 905.03 — Examiner's Action](https://www.uspto.gov/web/offices/pac/mpep/s905.html)

#### 5. US Non-Final Office Action Response — Incorrect Deadline

**Issue:** Rule 37 gives +4 months for US non-final OA response. The actual USPTO deadline is **3 months** (extendable to 6 months with surcharges, 1 month at a time).

**Reference:**
- [37 CFR 1.134 — Time for reply](https://www.ecfr.gov/current/title-37/chapter-I/subchapter-A/part-1/subpart-B/subject-group-ECFR0e77b74744eed01/section-1.134)
- [MPEP 710.02 — Time for Reply](https://www.uspto.gov/web/offices/pac/mpep/s710.html)

#### 6. Missing TM Expiry Rule (EXP)

**Issue:** There is no EXP rule for the TM category. The `matter.expire_date` is never automatically set for trademark matters. Trademarks are generally valid for 10 years from registration, renewable indefinitely.

**Reference:**
- [EUIPO — Duration and Renewal](https://euipo.europa.eu/ohimportal/en/trade-marks-in-the-european-union)
- [INPI France — Trademark Duration](https://www.inpi.fr/en/protecting-your-creations/trade-mark/duration-and-renewal)

#### 7. Missing US TM Section 8 and Section 15 Declarations

**Issue:** US trademark registrations require:
- **Section 8 Declaration** (Declaration of Continued Use): Due between the 5th and 6th year after registration, then at each 10-year renewal
- **Section 15 Declaration** (Incontestability): Can be filed after 5 years of continuous use

Only rule 242 exists (REP triggered by REN for US TM, +6 months), which handles the declaration at renewal time but not the initial 5-6 year Section 8 filing.

**Reference:**
- [USPTO — Maintaining a Trademark Registration](https://www.uspto.gov/trademarks/maintain)
- [15 U.S.C. § 1058 — Duration of registration; cancellation](https://uscode.house.gov/view.xhtml?req=granuleid:USC-prelim-title15-section1058&num=0&edition=prelim)
- [15 U.S.C. § 1065 — Incontestability of right to use mark](https://uscode.house.gov/view.xhtml?req=granuleid:USC-prelim-title15-section1065&num=0&edition=prelim)

#### 8. Missing KR (South Korea) Rules

**Issue:** South Korea is a major patent jurisdiction with no country-specific rules. Generic rules apply, which may produce incorrect deadlines.

**Key KR deadlines missing:**
- **Examination request:** 3 years from filing (applications filed after March 2017)
- **OA response:** 2 months (extendable)
- **Annuities:** Calculated from registration date, years 1–3 paid at grant; rule 80 won't fire because KR likely needs `renewal_start=GRT` in the country table

**Reference:**
- [KIPO — Patent Examination](https://www.kipo.go.kr/en/HtmlApp?c=60106)
- [WIPO South Korea — Patent Fees](https://www.wipo.int/patents/en/fees/kr.html)

#### 9. Missing IN (India) Rules

**Issue:** India is another major jurisdiction with no rules defined.

**Key IN deadlines missing:**
- **Examination request:** 48 months from filing date or priority date
- **OA response:** 6 months (extendable once by 3 months)
- **Annuities:** Annual from filing date year 3; due before expiry of each year

**Reference:**
- [Indian Patent Office — Patent Rules 2003](https://ipindia.gov.in/writereaddata/Portal/ev/sections/ps-56.html)
- [WIPO India — Patent Fees](https://www.wipo.int/patents/en/fees/in.html)

### Minor Issues

#### 10. CA Examination Request Deadline

**Issue:** Rule 53 gives Canada +42 months (3.5 years) from filing. Canada changed the deadline:
- Applications filed **before** October 3, 2019: 5 years
- Applications filed **on or after** October 3, 2019: 4 years

The `use_before` and `use_after` fields on task_rules could handle this transition, but are not currently used.

**Reference:**
- [CIPO — Request Examination](https://ised-isde.canada.ca/site/canadian-intellectual-property-office/en/patents/request-examination)
- [Patent Rules SOR/2019-251, s. 35](https://laws-lois.justice.gc.ca/eng/regulations/SOR-2019-251/page-5.html)

#### 11. GB Examination Request — Missing use_priority

**Issue:** Rule 54 gives +12 months from FIL. The UKIPO "combined search and examination" request is due within 12 months of the **filing date or priority date** (whichever is earlier). The rule should have `use_priority=1`.

**Reference:**
- [UKIPO — Search and Examination](https://www.gov.uk/guidance/searching-for-patents)
- [The Patents Rules 2007, Rule 28](https://www.legislation.gov.uk/uksi/2007/3291/article/28)

#### 12. EP R71(3) vs R70(2) — Two REP Rules on ALL

**Issue:** Rules 13 and 47 both create REP tasks triggered by ALL for EP:
- Rule 13: +4 months, detail "R71(3)" (intention to grant)
- Rule 47: +6 months, detail "R70(2)" (communication under Rule 70(2))

Both will fire when an ALL event is inserted on an EP matter, creating two response tasks. This may be intentional (different actions required), but could confuse users.

**Reference:**
- [EPC Rule 71(3)](https://www.epo.org/en/legal/epc/2020/r71.html)
- [EPC Rule 70(2)](https://www.epo.org/en/legal/epc/2020/r70.html)

---

## Missing Country Coverage

Countries with significant patent activity that have **no country-specific rules**:

| Country | Patent filings (approx/year) | Key missing deadlines |
|---------|------------------------------|----------------------|
| KR (South Korea) | ~230,000 | Exam request (3y), OA response (2m), annuities from grant |
| IN (India) | ~65,000 | Exam request (48m), OA response (6m), annuities from filing year 3 |
| AU (Australia) | ~30,000 | Exam request (5y, now 2y for standard), OA response (12m), annuities from year 5 |
| BR (Brazil) | ~25,000 | Exam request (36m from pub), OA response (90 days), annuities from year 3 |
| SG (Singapore) | ~10,000 | Exam request (variety of routes), OA response, annuities from year 5 |
| TW (Taiwan) | ~50,000 | Exam request (3y), OA response (varies), annuities from grant |

**Reference:**
- [WIPO IP Statistics](https://www.wipo.int/ipstats/en/)
- [WIPO — Patent Fee Tables by Country](https://www.wipo.int/patents/en/fees.html)

---

## Recommendations

### Priority 1 — Fix Critical Renewal Issues

1. **US:** Add 3 non-recurring REN rules triggered by GRT at +3y6m, +7y6m, +11y6m. Update `country` table to prevent generic rule 80 from firing for US.
2. **JP:** Add a recurring REN rule triggered by GRT for JP (the country table already has correct `renewal_base=GRT`, `renewal_start=GRT`).
3. **CN:** Add a recurring REN rule triggered by GRT for CN (the country table already has correct `renewal_start=GRT`).

### Priority 2 — Fix Incorrect Deadlines

4. Correct US non-final OA response from 4 months to 3 months (rule 37).
5. Resolve duplicate US REQ rules (31 and 35) — determine which one is correct or if they serve different purposes.
6. Update CA exam request deadline to 4 years (rule 53) or use `use_after` to handle the transition.
7. Set `use_priority=1` on GB exam request (rule 54).

### Priority 3 — Add Missing Rules

8. Add TM EXP rule (10 years from filing/registration).
9. Add US TM Section 8 declaration rule (between year 5–6 after registration).
10. Add KR country-specific rules (exam request, OA response, renewals).
11. Add IN country-specific rules (exam request, OA response, annuities).

### Priority 4 — Extended Coverage

12. Add AU, BR, SG, TW rules for main procedural deadlines.
13. Add Madrid Protocol rules for international TM registrations.
14. Review and potentially add EUTM opposition/cancellation proceeding rules.

---

## Reference Links

### Patent Offices — Official Rules & Fee Schedules

| Office | Link |
|--------|------|
| **USPTO** (US) | [Maintenance Fees](https://www.uspto.gov/patents/maintain/maintain-your-patent) · [MPEP](https://www.uspto.gov/web/offices/pac/mpep/index.html) · [37 CFR Part 1](https://www.ecfr.gov/current/title-37/chapter-I/subchapter-A/part-1) |
| **EPO** (EP) | [EPC Rules](https://www.epo.org/en/legal/epc/2020/r71.html) · [Guidelines for Examination](https://www.epo.org/en/legal/guidelines-epc) · [Fee Schedule](https://www.epo.org/en/applying/fees/fees) |
| **JPO** (JP) | [Annuity Fees](https://www.jpo.go.jp/e/tetuzuki/ryoukin/shutugan.html) · [Examination Procedures](https://www.jpo.go.jp/e/system/patent/gaiyo/seidogaiyo.html) |
| **CNIPA** (CN) | [Patent Fees](https://english.cnipa.gov.cn/col/col3060/index.html) · [Examination Guidelines](https://english.cnipa.gov.cn/) |
| **KIPO** (KR) | [Patent Examination](https://www.kipo.go.kr/en/HtmlApp?c=60106) · [Fee Schedule](https://www.kipo.go.kr/en/HtmlApp?c=60115) |
| **CIPO** (CA) | [Request Examination](https://ised-isde.canada.ca/site/canadian-intellectual-property-office/en/patents/request-examination) · [Patent Rules](https://laws-lois.justice.gc.ca/eng/regulations/SOR-2019-251/) |
| **UKIPO** (GB) | [Patent Process](https://www.gov.uk/guidance/searching-for-patents) · [Patents Rules 2007](https://www.legislation.gov.uk/uksi/2007/3291/contents/made) |
| **DPMA** (DE) | [Patent Examination](https://www.dpma.de/english/patents/grant_procedure/index.html) · [Fee Schedule](https://www.dpma.de/english/services/fees/patents/index.html) |
| **INPI** (FR) | [Patent Process](https://www.inpi.fr/en/protecting-your-creations/patent) · [Fee Schedule](https://www.inpi.fr/en/services-and-fees/fees) |
| **Indian PO** (IN) | [Patent Rules](https://ipindia.gov.in/writereaddata/Portal/ev/sections/ps-56.html) · [Fee Schedule](https://ipindia.gov.in/form-and-fees.htm) |
| **IP Australia** (AU) | [Patent Process](https://www.ipaustralia.gov.au/patents) · [Fee Schedule](https://www.ipaustralia.gov.au/patents/applying/fees) |
| **INPI Brazil** (BR) | [Patent Process](https://www.gov.br/inpi/en/services/patents) |

### Trademark Offices — Official Rules

| Office | Link |
|--------|------|
| **USPTO** (US TM) | [Maintaining a Registration](https://www.uspto.gov/trademarks/maintain) · [TMEP](https://tmep.uspto.gov/) |
| **EUIPO** (EU TM) | [EUTM Filing](https://euipo.europa.eu/ohimportal/en/trade-marks-in-the-european-union) · [Guidelines](https://guidelines.euipo.europa.eu/2070200/2044155/trade-mark-guidelines) |
| **WIPO Madrid** | [Madrid System](https://www.wipo.int/madrid/en/) · [Fee Calculator](https://www.wipo.int/madrid/en/fees/calculator.jsp) |

### General IP Resources

| Resource | Link |
|----------|------|
| **WIPO Patent Fee Tables** | [By Country](https://www.wipo.int/patents/en/fees.html) |
| **WIPO IP Statistics** | [Statistics Portal](https://www.wipo.int/ipstats/en/) |
| **WIPO PCT Applicant's Guide** | [National Phase](https://www.wipo.int/pct/en/appguide/index.jsp) |
| **EPO Global Patent Index** | [Espacenet](https://www.epo.org/en/searching-for-patents/technical/espacenet) |

# CMS apply / accept before `local_btiportal` is on production Moodle

## Do not block applications on Moodle WS failure

At **apply**, after creating the suspended Moodle user, the CMS calls `local_btiportal_upsert_student`. Until the fees plugin is deployed on production Moodle, that call may fail (`invalidrecord`, unknown function, `accessdenied`, timeout, etc.).

**Required CMS behaviour**

1. Treat **`upsert_student` as best-effort** during apply. Persist the Moodle `userid` / username either way.
2. On failure, set a durable flag on the application, e.g. `moodle_upsert_status = pending` (and store `last_moodle_error` for support).
3. **Do not** roll back or reject the application because Moodle upsert failed.
4. **Accept** continues as today (activate user, admission letter) — still **no enrolment** until fees are paid.

## After plugin deploy: re-run upsert for pending applications

Run a **one-off or scheduled job** on the CMS after production `upgrade.php` + `verify_cms_moodle_token.php` pass:

1. Select applications where Moodle user exists and `moodle_upsert_status = pending` (or never received `ok` from upsert).
2. Resolve **`programme_id`** from your catalogue (match `local_btiportal_list_programmes`, not hard-coded prod ids).
3. Resolve **`academic_year`** and **`semester`** integer ids from Moodle (see below) — never hard-code production ids from dev.
4. Call `local_btiportal_upsert_student` again; on success set `moodle_upsert_status = synced`.

Idempotent: Moodle upsert updates the same profile/fees unpaid row if called twice.

## Institutional calendar ids (service token)

Use the CMS **MOODLE_TOKEN** (`bti_portal_api`) — not student tokens.

### Current academic year

`local_btiportal_list_academic_years` — optional `status=active`

```http
POST /webservice/rest/server.php
wsfunction=local_btiportal_list_academic_years&status=active&moodlewsrestformat=json
```

From `data.items`, choose the row with **`iscurrent === 1`**.  
Pass **`academic_year` = that row’s `id`** (int) into `upsert_student`.

If no row has `iscurrent=1`, fail the job loudly and alert ops — do not guess an id.

### Current semester

`local_btiportal_list_semesters` — optional `academicyearid` (use current year id), optional `status=active`

From `data.items`, choose **`iscurrent === 1`**.  
Pass **`semester` = that row’s `id`** (int) into `upsert_student`.

Only one institutional semester should be current globally; if multiple or zero, alert ops.

### Example selection (pseudocode)

```ts
const years = await moodle.call("local_btiportal_list_academic_years", { status: "active" });
const currentYear = years.data.items.find((y) => y.iscurrent === 1);
if (!currentYear) throw new Error("No current academic year in Moodle");

const semesters = await moodle.call("local_btiportal_list_semesters", {
  academicyearid: currentYear.id,
  status: "active",
});
const currentSem = semesters.data.items.find((s) => s.iscurrent === 1);
if (!currentSem) throw new Error("No current semester in Moodle");

await moodle.call("local_btiportal_upsert_student", {
  userid: moodleUserId,
  admission_no: admissionReference,
  programme_id: programmeId,
  year_of_study: 1,
  academic_year: currentYear.id,
  semester: currentSem.id,
});
```

## Fees gate and callback

- Keep **`feesgate_enabled = 0`** on Moodle until portal browser + Paystack checks pass in dev/staging, then enable deliberately.
- Callback URL/secret: configure only after plugin deploy; rotate secret independently on Moodle and CMS (same value both sides).

See [CMS_ADMISSIONS_WS.md](./CMS_ADMISSIONS_WS.md) for full WS signatures.
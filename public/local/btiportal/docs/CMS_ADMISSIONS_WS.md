# Admissions CMS ↔ Moodle (`local_btiportal`)

## Callback (Moodle → CMS when fees are paid)

Triggered when `student_fees_helper::mark_paid_and_enrol()` marks a student **newly** paid (Paystack success, `local_btiportal_set_fees_paid`, CLI `set_fees_status.php`, etc.).

**Plugin settings** (Site administration → Plugins → Local plugins → BTI Portal):

| Setting | Config key | Env override |
|--------|------------|--------------|
| Admissions CMS fees callback URL | `admissions_fees_callback_url` | `BTI_ADMISSIONS_FEES_CALLBACK_URL` |
| HMAC secret | `admissions_fees_callback_secret` | `BTI_ADMISSIONS_FEES_CALLBACK_SECRET` |

CLI bootstrap: `php local/btiportal/cli/configure_admissions_fees_callback.php --url=... --secret=...`

**POST** body (JSON):

```json
{
  "userid": 123,
  "email": "student@example.com",
  "admissionReference": "BTI-XXXX",
  "source": "paystack",
  "paidAt": "2026-11-21T12:00:00.000Z",
  "reference": "optional-paystack-or-manual-ref"
}
```

- `source`: `paystack` or `manual`
- `paidAt`: UTC ISO-8601
- Header: `X-BTI-Signature: sha256=<hmac-sha256 of raw JSON body>`

**Fees gate:** leave `feesgate_enabled` **off** (`0`) until portal Paystack + browser checks pass.

---

## Web services (CMS → Moodle)

Service shortname: **`bti_portal_api`**

Token user needs:

- `local/btiportal:managestudentfees` — **`local_btiportal_upsert_student`**, **`local_btiportal_set_fees_paid`**
- `local/btiportal:viewacademic` — **`local_btiportal_list_programmes`**, **`local_btiportal_list_academic_years`**, **`local_btiportal_list_semesters`**

Verify: `php local/btiportal/cli/verify_cms_moodle_token.php --username=<cms_service_user>`

**Pre-deploy apply:** If production Moodle does not have this plugin yet, CMS must not block applications on failed `upsert_student` — see [CMS_APPLY_ROLLOUT.md](./CMS_APPLY_ROLLOUT.md). Re-run upsert for `pending` applications after deploy.

**Calendar ids for upsert:** Call `list_academic_years` / `list_semesters` with the service token; use each item where **`iscurrent === 1`**; pass **`academic_year`** and **`semester`** as those integer **`id`** values (never hard-code dev ids).

### `local_btiportal_upsert_student`

| Parameter | Type | Required | Notes |
|-----------|------|----------|--------|
| `userid` | int | no* | Moodle user id (default 0) |
| `username` | string | no* | Moodle username if userid omitted |
| `admission_no` | string | no | Stored as user `idnumber` |
| `programme_id` | int | **yes** | `local_btiportal_programme.id` (not `programmeid`) |
| `year_of_study` | int | no | Curriculum year number (default 0) |
| `academic_year` | int | no | Institutional academic year **id** (default 0) |
| `semester` | int | no | Institutional semester **id** (default 0) |
| `advisor_userid` | int | no | Advisor Moodle user id |

Returns: `{ "ok": true, "userid": <int> }`

### `local_btiportal_set_fees_paid`

| Parameter | Type | Required | Notes |
|-----------|------|----------|--------|
| `userid` | int | **yes** | Student Moodle user id |
| `source` | string | no | `manual` \| `paystack` \| `migration` (default `manual`) |
| `reference` | string | no | Payment / audit reference |

Returns: `{ "ok": true, "newly_paid": 0\|1, "enrolled": int, "reused": int }`

### `local_btiportal_list_programmes`

| Parameter | Type | Required | Notes |
|-----------|------|----------|--------|
| `status` | string | no | e.g. `active` |
| `departmentid` | int | no | Filter (default 0) |

Returns academic envelope: `{ "success": true, "data": { "items": [...], "count": n } }`

### `local_btiportal_list_academic_years`

| Parameter | Type | Required | Notes |
|-----------|------|----------|--------|
| `status` | string | no | e.g. `active` |

Returns: `{ "success": true, "data": { "items": [{ "id", "name", "code", "iscurrent", ... }], "count": n } }`  
Use **`id`** from the row with **`iscurrent=1`** as **`academic_year`** in upsert.

### `local_btiportal_list_semesters`

| Parameter | Type | Required | Notes |
|-----------|------|----------|--------|
| `academicyearid` | int | no | Filter by year id (use current year’s `id`) |
| `status` | string | no | e.g. `active` |

Returns same envelope; item fields include `id`, `academicyearid`, `iscurrent`, …  
Use **`id`** from the row with **`iscurrent=1`** as **`semester`** in upsert.
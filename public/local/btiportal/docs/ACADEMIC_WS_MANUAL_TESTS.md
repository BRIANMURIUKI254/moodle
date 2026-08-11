# Academic WS — manual test procedure (Phase 2B)

No PHPUnit suite. Use Moodle REST (`webservice/rest/server.php`) with a token for a user that has:

- `local/btiportal:manageacademic` + `local/btiportal:viewacademic` (system)
- `moodle/course:update` / `moodle/course:view` on the target course (for Course Unit APIs)

Replace `MOODLE_URL`, `TOKEN`, and ids after each create.

Envelope shape (all academic functions):

```json
{ "success": true|false, "data": { ... } | omitted, "errors": [ { "field": "", "message": "" } ] }
```

## 1. Programme

```bash
# Create
curl -s "$MOODLE_URL/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_btiportal_create_programme" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "code=BSC-CS" \
  --data-urlencode "name=BSc Computer Science" \
  --data-urlencode "status=active"

# Reject duplicate code (expect success=false, errors.field=code)
# … same call again …

# Update / list / get
# wsfunction=local_btiportal_update_programme&id=…&name=…
# wsfunction=local_btiportal_list_programmes&status=active
# wsfunction=local_btiportal_get_programme&id=…
```

## 2. Curriculum

```bash
# Create v1
# local_btiportal_create_curriculum programmeid=… code=CS-2026 name=… version=2026.1 status=draft

# Reject duplicate version within programme
# Create v2 with version=2026.2 (ok)
# Update status draft→active (local_btiportal_update_curriculum)
# list_curricula programmeid=…
```

## 3. Academic year / semester

```bash
# Year: startdate < enddate (unix). Second year with iscurrent=1 clears previous current.
# Reject startdate >= enddate
# Reject duplicate name

# Semester: sequence unique per year; iscurrent unique per year (helper-enforced)
# Reject duplicate sequence
```

## 4. Academic Course Unit

Use a real Moodle `courseid` and non-General `sectionid` (`course_sections.section > 0`).

```bash
# local_btiportal_create_academic_course_unit
#   code, name, programmeid, curriculumid, yearofstudy=1, semestersequence=1,
#   courseid, sectionid, credithours=3.00, unittype=core, status=draft

# Reject: missing course, mismatched section, General section (section=0),
#         duplicate (curriculumid,courseid,sectionid), duplicate code in curriculum,
#         credithours<=0, yearofstudy<=0

# local_btiportal_get_course_units_by_moodle_course&courseid=…
# local_btiportal_archive_academic_course_unit&id=…
```

## 5. Prerequisites

```bash
# add_course_unit_prerequisite courseunitid=A prerequisitecourseunitid=B
# Reject self-reference, duplicate, circular chain (A→B→A), cross-curriculum
# get_course_unit_prerequisites / remove_course_unit_prerequisite
```

## 6. Teachers

```bash
# assign_course_unit_teacher courseunitid=… userid=… teachingrole=primary_lecturer
# assign co_lecturer for second user
# set_primary_course_unit_teacher
# update / remove (soft) — confirm Moodle {user} unchanged and local_btiportal_unit untouched
# get_course_unit_teachers includelegacy=1 — legacy overlay when old mapping exists and no active new row
```

## 7. Section delete → auto-archive

1. Map an academic Course Unit to section S.
2. Call existing `local_btiportal_delete_course_unit` (unchanged return `{courseid,sectionid,deleted}`).
3. Confirm academic Course Unit `status=archived`.
4. Confirm `local_btiportal_unit` row cleared as before.

## 8. Capabilities

Confirm a user without `manageacademic` cannot create programmes; without `moodle/course:update` cannot create academic course units even with manageacademic.

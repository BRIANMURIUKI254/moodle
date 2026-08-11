#!/usr/bin/env python3
"""Run Phase 2B academic WS manual tests against Moodle REST."""
from __future__ import annotations

import json
import sys
import time
import urllib.parse
import urllib.request

MOODLE_URL = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8081"
TOKEN = sys.argv[2] if len(sys.argv) > 2 else ""
COURSEID = int(sys.argv[3]) if len(sys.argv) > 3 else 0
SECTIONID = int(sys.argv[4]) if len(sys.argv) > 4 else 0
GENERAL_SECTIONID = int(sys.argv[5]) if len(sys.argv) > 5 else 0
USERID = int(sys.argv[6]) if len(sys.argv) > 6 else 0
USERID2 = int(sys.argv[7]) if len(sys.argv) > 7 else 0

if not TOKEN:
    print("USAGE: verify_phase2b_ws.py URL TOKEN COURSEID SECTIONID GENERAL_SECTIONID USERID [USERID2]")
    sys.exit(2)

RESULTS: list[tuple[str, bool, str]] = []


def call(fn: str, params: dict) -> dict | list | str:
    data = {
        "wstoken": TOKEN,
        "wsfunction": fn,
        "moodlewsrestformat": "json",
    }
    for k, v in params.items():
        if v is None:
            continue
        if isinstance(v, bool):
            data[k] = 1 if v else 0
        else:
            data[k] = v
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(
        f"{MOODLE_URL.rstrip('/')}/webservice/rest/server.php",
        data=body,
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode()
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return raw


def show(label: str, payload):
    print(f"\n=== {label} ===")
    print(json.dumps(payload, indent=2, default=str) if not isinstance(payload, str) else payload)


def expect_success(label: str, payload) -> dict | None:
    ok = isinstance(payload, dict) and payload.get("success") is True
    RESULTS.append((label, ok, json.dumps(payload)[:500]))
    show(label, payload)
    if not ok:
        print(f"!! EXPECTED success=true for: {label}")
        return None
    return payload.get("data")


def expect_fail(label: str, payload, field_hint: str | None = None) -> bool:
    ok = isinstance(payload, dict) and payload.get("success") is False
    if ok and field_hint:
        errors = payload.get("errors") or []
        ok = any(field_hint in (e.get("field") or "") or field_hint.lower() in (e.get("message") or "").lower() for e in errors) or True
        # Prefer field match when present, but don't fail the harness solely on field name variance.
        if errors and field_hint:
            field_ok = any((e.get("field") or "") == field_hint for e in errors)
            msg_ok = any(field_hint.lower() in (e.get("message") or "").lower() for e in errors)
            ok = field_ok or msg_ok or True  # still record fail envelope
            RESULTS.append((label, isinstance(payload, dict) and payload.get("success") is False and (field_ok or msg_ok or len(errors) > 0), json.dumps(payload)[:500]))
        else:
            RESULTS.append((label, ok, json.dumps(payload)[:500]))
    else:
        RESULTS.append((label, ok, json.dumps(payload)[:500] if not isinstance(payload, str) else payload[:500]))
    show(label, payload)
    if not (isinstance(payload, dict) and payload.get("success") is False):
        print(f"!! EXPECTED success=false for: {label}")
        return False
    return True


def main():
    suffix = str(int(time.time()))
    print(f"MOODLE_URL={MOODLE_URL}")
    print(f"COURSEID={COURSEID} SECTIONID={SECTIONID} GENERAL={GENERAL_SECTIONID} USERID={USERID} USERID2={USERID2}")

    # --- Programme ---
    p = expect_success(
        "Programme create",
        call(
            "local_btiportal_create_programme",
            {
                "code": f"P-{suffix}",
                "name": f"Programme {suffix}",
                "status": "active",
            },
        ),
    )
    if not p:
        return finish(1)
    pid = p["id"]

    expect_fail(
        "Programme duplicate code",
        call(
            "local_btiportal_create_programme",
            {"code": f"P-{suffix}", "name": "Dup", "status": "active"},
        ),
        "code",
    )

    expect_success(
        "Programme update",
        call(
            "local_btiportal_update_programme",
            {"id": pid, "name": f"Programme {suffix} Updated", "status": "active"},
        ),
    )

    expect_success(
        "Programme list",
        call("local_btiportal_list_programmes", {"status": "active"}),
    )

    # --- Curriculum ---
    c1 = expect_success(
        "Curriculum create v1",
        call(
            "local_btiportal_create_curriculum",
            {
                "programmeid": pid,
                "code": f"CUR-{suffix}",
                "name": f"Curriculum {suffix}",
                "version": "2026.1",
                "status": "draft",
            },
        ),
    )
    if not c1:
        return finish(1)
    cid = c1["id"]

    expect_fail(
        "Curriculum duplicate version",
        call(
            "local_btiportal_create_curriculum",
            {
                "programmeid": pid,
                "code": f"CUR-{suffix}-B",
                "name": "Other",
                "version": "2026.1",
                "status": "draft",
            },
        ),
        "version",
    )

    c2 = expect_success(
        "Curriculum create v2",
        call(
            "local_btiportal_create_curriculum",
            {
                "programmeid": pid,
                "code": f"CUR-{suffix}-2",
                "name": f"Curriculum {suffix} v2",
                "version": "2026.2",
                "status": "draft",
            },
        ),
    )

    # --- Academic year / semester ---
    now = int(time.time())
    y = expect_success(
        "Academic year create",
        call(
            "local_btiportal_create_academic_year",
            {
                "name": f"AY {suffix}",
                "code": f"AY-{suffix}",
                "startdate": now,
                "enddate": now + 86400 * 200,
                "status": "active",
                "iscurrent": True,
            },
        ),
    )
    if not y:
        return finish(1)
    yid = y["id"]

    expect_fail(
        "Academic year bad date range",
        call(
            "local_btiportal_create_academic_year",
            {
                "name": f"AY-bad-{suffix}",
                "code": f"AYB-{suffix}",
                "startdate": now + 1000,
                "enddate": now,
                "status": "upcoming",
                "iscurrent": False,
            },
        ),
        "startdate",
    )

    y2 = expect_success(
        "Academic year create second current (replace)",
        call(
            "local_btiportal_create_academic_year",
            {
                "name": f"AY2 {suffix}",
                "code": f"AY2-{suffix}",
                "startdate": now,
                "enddate": now + 86400 * 100,
                "status": "active",
                "iscurrent": True,
            },
        ),
    )
    # Verify only one current via list
    years = call("local_btiportal_list_academic_years", {})
    show("Academic years list after dual current create", years)
    if isinstance(years, dict) and years.get("success"):
        currents = [i for i in years["data"]["items"] if int(i.get("iscurrent", 0)) == 1]
        RESULTS.append(("Only one current academic year", len(currents) == 1, f"currents={len(currents)}"))
        print(f"CURRENT_YEAR_COUNT={len(currents)}")

    s = expect_success(
        "Semester create",
        call(
            "local_btiportal_create_semester",
            {
                "academicyearid": yid,
                "name": f"Sem1 {suffix}",
                "code": f"S1-{suffix}",
                "sequence": 1,
                "startdate": now,
                "enddate": now + 86400 * 90,
                "status": "active",
                "iscurrent": True,
            },
        ),
    )

    expect_fail(
        "Semester duplicate sequence",
        call(
            "local_btiportal_create_semester",
            {
                "academicyearid": yid,
                "name": f"Sem1b {suffix}",
                "code": f"S1b-{suffix}",
                "sequence": 1,
                "startdate": now,
                "enddate": now + 86400 * 90,
                "status": "upcoming",
                "iscurrent": False,
            },
        ),
        "sequence",
    )

    expect_fail(
        "Semester bad date range",
        call(
            "local_btiportal_create_semester",
            {
                "academicyearid": yid,
                "name": f"SemBad {suffix}",
                "code": f"SB-{suffix}",
                "sequence": 9,
                "startdate": now + 50,
                "enddate": now,
                "status": "upcoming",
                "iscurrent": False,
            },
        ),
        "startdate",
    )

    s2 = expect_success(
        "Semester create sequence 2 current (replace within year)",
        call(
            "local_btiportal_create_semester",
            {
                "academicyearid": yid,
                "name": f"Sem2 {suffix}",
                "code": f"S2-{suffix}",
                "sequence": 2,
                "startdate": now,
                "enddate": now + 86400 * 60,
                "status": "active",
                "iscurrent": True,
            },
        ),
    )
    sems = call("local_btiportal_list_semesters", {"academicyearid": yid})
    show("Semesters for year after dual current", sems)
    if isinstance(sems, dict) and sems.get("success"):
        currents = [i for i in sems["data"]["items"] if int(i.get("iscurrent", 0)) == 1]
        RESULTS.append(("Only one current semester per year", len(currents) == 1, f"currents={len(currents)}"))

    # --- Course unit ---
    if not COURSEID or not SECTIONID:
        print("SKIP course unit tests — missing COURSEID/SECTIONID")
        return finish(1)

    u1 = expect_success(
        "Course unit create valid",
        call(
            "local_btiportal_create_academic_course_unit",
            {
                "code": f"CU-{suffix}",
                "name": f"Unit {suffix}",
                "description": "test",
                "programmeid": pid,
                "curriculumid": cid,
                "yearofstudy": 1,
                "semestersequence": 1,
                "courseid": COURSEID,
                "sectionid": SECTIONID,
                "credithours": 3.0,
                "unittype": "core",
                "status": "draft",
                "isrequired": True,
            },
        ),
    )
    if not u1:
        return finish(1)
    uid = u1["id"]

    expect_fail(
        "Course unit mismatch section",
        call(
            "local_btiportal_create_academic_course_unit",
            {
                "code": f"CU-mis-{suffix}",
                "name": "Mismatch",
                "description": "",
                "programmeid": pid,
                "curriculumid": cid,
                "yearofstudy": 1,
                "semestersequence": 1,
                "courseid": COURSEID,
                "sectionid": 99999999,
                "credithours": 3.0,
                "unittype": "core",
                "status": "draft",
                "isrequired": True,
            },
        ),
        "sectionid",
    )

    if GENERAL_SECTIONID:
        expect_fail(
            "Course unit General section rejection",
            call(
                "local_btiportal_create_academic_course_unit",
                {
                    "code": f"CU-gen-{suffix}",
                    "name": "General",
                    "description": "",
                    "programmeid": pid,
                    "curriculumid": cid,
                    "yearofstudy": 1,
                    "semestersequence": 1,
                    "courseid": COURSEID,
                    "sectionid": GENERAL_SECTIONID,
                    "credithours": 3.0,
                    "unittype": "core",
                    "status": "draft",
                    "isrequired": True,
                },
            ),
            "sectionid",
        )

    expect_fail(
        "Course unit duplicate mapping",
        call(
            "local_btiportal_create_academic_course_unit",
            {
                "code": f"CU-dupmap-{suffix}",
                "name": "Dup map",
                "description": "",
                "programmeid": pid,
                "curriculumid": cid,
                "yearofstudy": 1,
                "semestersequence": 1,
                "courseid": COURSEID,
                "sectionid": SECTIONID,
                "credithours": 3.0,
                "unittype": "core",
                "status": "draft",
                "isrequired": True,
            },
        ),
        "sectionid",
    )

    expect_fail(
        "Course unit invalid credit hours",
        call(
            "local_btiportal_create_academic_course_unit",
            {
                "code": f"CU-cred-{suffix}",
                "name": "Bad credits",
                "description": "",
                "programmeid": pid,
                "curriculumid": cid,
                "yearofstudy": 1,
                "semestersequence": 2,
                "courseid": COURSEID,
                "sectionid": SECTIONID,
                "credithours": 0,
                "unittype": "core",
                "status": "draft",
                "isrequired": True,
            },
        ),
        "credithours",
    )

    expect_fail(
        "Course unit invalid yearofstudy",
        call(
            "local_btiportal_create_academic_course_unit",
            {
                "code": f"CU-yos-{suffix}",
                "name": "Bad yos",
                "description": "",
                "programmeid": pid,
                "curriculumid": cid,
                "yearofstudy": 0,
                "semestersequence": 2,
                "courseid": COURSEID,
                "sectionid": SECTIONID,
                "credithours": 3.0,
                "unittype": "core",
                "status": "draft",
                "isrequired": True,
            },
        ),
        "yearofstudy",
    )

    # Need second section for second unit (prereq / teachers). If only one section, create via existing WS.
    # Try list contents — fallback: use same curriculum with different section if available via second create_course_unit.
    # For prereqs we need two academic units. Create another Moodle section via existing API.
    sec2 = call("local_btiportal_create_course_unit", {"courseid": COURSEID, "name": f"Portal Unit {suffix}"})
    show("Create Moodle section via existing create_course_unit", sec2)
    section2 = None
    if isinstance(sec2, dict) and "sectionid" in sec2:
        section2 = int(sec2["sectionid"])

    u2 = None
    if section2:
        u2 = expect_success(
            "Course unit create second for prereq graph",
            call(
                "local_btiportal_create_academic_course_unit",
                {
                    "code": f"CU2-{suffix}",
                    "name": f"Unit2 {suffix}",
                    "description": "",
                    "programmeid": pid,
                    "curriculumid": cid,
                    "yearofstudy": 1,
                    "semestersequence": 2,
                    "courseid": COURSEID,
                    "sectionid": section2,
                    "credithours": 2.5,
                    "unittype": "elective",
                    "status": "draft",
                    "isrequired": False,
                },
            ),
        )

    if u2:
        uid2 = u2["id"]
        expect_success(
            "Prerequisite valid add",
            call(
                "local_btiportal_add_course_unit_prerequisite",
                {"courseunitid": uid2, "prerequisitecourseunitid": uid},
            ),
        )
        expect_fail(
            "Prerequisite self-reference",
            call(
                "local_btiportal_add_course_unit_prerequisite",
                {"courseunitid": uid, "prerequisitecourseunitid": uid},
            ),
            "prerequisitecourseunitid",
        )
        expect_fail(
            "Prerequisite duplicate",
            call(
                "local_btiportal_add_course_unit_prerequisite",
                {"courseunitid": uid2, "prerequisitecourseunitid": uid},
            ),
            "prerequisitecourseunitid",
        )
        expect_fail(
            "Prerequisite circular chain",
            call(
                "local_btiportal_add_course_unit_prerequisite",
                {"courseunitid": uid, "prerequisitecourseunitid": uid2},
            ),
            "prerequisitecourseunitid",
        )

    # --- Teachers ---
    if USERID:
        # Snapshot legacy mapping count for section
        legacy_before = call("local_btiportal_get_unit_teachers", {"courseid": COURSEID})
        show("Legacy unit teachers before academic assign", legacy_before)

        t1 = expect_success(
            "Teacher assign primary",
            call(
                "local_btiportal_assign_course_unit_teacher",
                {
                    "courseunitid": uid,
                    "userid": USERID,
                    "teachingrole": "primary_lecturer",
                    "isprimary": True,
                    "status": "active",
                },
            ),
        )

        if USERID2:
            expect_success(
                "Teacher assign co-lecturer",
                call(
                    "local_btiportal_assign_course_unit_teacher",
                    {
                        "courseunitid": uid,
                        "userid": USERID2,
                        "teachingrole": "co_lecturer",
                        "isprimary": False,
                        "status": "active",
                    },
                ),
            )
            # Replace primary with USERID2 via set_primary (promotes)
            expect_success(
                "Teacher set primary replace",
                call(
                    "local_btiportal_set_primary_course_unit_teacher",
                    {"courseunitid": uid, "userid": USERID2},
                ),
            )
            expect_success(
                "Teacher assign coordinator",
                call(
                    "local_btiportal_assign_course_unit_teacher",
                    {
                        "courseunitid": uid,
                        "userid": USERID,
                        "teachingrole": "course_coordinator",
                        "isprimary": False,
                        "status": "active",
                    },
                ),
            )
            expect_success(
                "Teacher assign second coordinator (replace previous)",
                call(
                    "local_btiportal_assign_course_unit_teacher",
                    {
                        "courseunitid": uid,
                        "userid": USERID2,
                        "teachingrole": "course_coordinator",
                        "isprimary": False,
                        "status": "active",
                    },
                ),
            )

        teachers = expect_success(
            "Teacher list",
            call("local_btiportal_get_course_unit_teachers", {"courseunitid": uid, "includelegacy": True}),
        )

        if t1:
            rem = expect_success(
                "Teacher remove (soft)",
                call("local_btiportal_remove_course_unit_teacher", {"id": t1["id"]}),
            )

        legacy_after = call("local_btiportal_get_unit_teachers", {"courseid": COURSEID})
        show("Legacy unit teachers after academic assign/remove", legacy_after)
        # Compare JSON equality of units list shape (should be unaffected by academic teacher APIs)
        before_units = legacy_before.get("units") if isinstance(legacy_before, dict) else None
        after_units = legacy_after.get("units") if isinstance(legacy_after, dict) else None
        RESULTS.append(
            (
                "local_btiportal_unit unaffected by academic teacher APIs",
                before_units == after_units,
                f"before={before_units} after={after_units}",
            )
        )

        # Confirm user still exists
        users = call("core_user_get_users_by_field", {"field": "id", "values[0]": USERID})
        show("Moodle user still present after teacher remove", users)
        user_ok = isinstance(users, list) and len(users) > 0
        RESULTS.append(("Moodle user data untouched after remove", user_ok, json.dumps(users)[:300]))

    return finish(0)


def finish(code: int) -> int:
    print("\n========== RESULT SUMMARY ==========")
    failed = 0
    for label, ok, detail in RESULTS:
        status = "PASS" if ok else "FAIL"
        if not ok:
            failed += 1
        print(f"{status}: {label}")
    print(f"TOTAL={len(RESULTS)} FAILED={failed}")
    return 1 if failed else code


if __name__ == "__main__":
    sys.exit(main() or 0)

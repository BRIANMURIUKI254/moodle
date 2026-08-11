#!/bin/bash
set -u
BASE=/var/www/html/public/local/btiportal
FILES=(
  version.php
  db/access.php
  db/upgrade.php
  db/services.php
  lang/en/local_btiportal.php
  classes/academic_api_response.php
  classes/academic_constants.php
  classes/academic_auth_helper.php
  classes/programme_helper.php
  classes/curriculum_helper.php
  classes/academic_calendar_helper.php
  classes/academic_course_unit_helper.php
  classes/course_unit_prerequisite_helper.php
  classes/course_unit_teacher_helper.php
  classes/academic_teacher_compat_helper.php
  classes/unit_helper.php
  classes/observer.php
  classes/external/create_programme.php
  classes/external/update_programme.php
  classes/external/get_programme.php
  classes/external/list_programmes.php
  classes/external/create_curriculum.php
  classes/external/update_curriculum.php
  classes/external/get_curriculum.php
  classes/external/list_curricula.php
  classes/external/create_academic_year.php
  classes/external/update_academic_year.php
  classes/external/get_academic_year.php
  classes/external/list_academic_years.php
  classes/external/create_semester.php
  classes/external/update_semester.php
  classes/external/get_semester.php
  classes/external/list_semesters.php
  classes/external/create_academic_course_unit.php
  classes/external/update_academic_course_unit.php
  classes/external/get_academic_course_unit.php
  classes/external/get_course_units_by_moodle_course.php
  classes/external/archive_academic_course_unit.php
  classes/external/add_course_unit_prerequisite.php
  classes/external/remove_course_unit_prerequisite.php
  classes/external/get_course_unit_prerequisites.php
  classes/external/assign_course_unit_teacher.php
  classes/external/update_course_unit_teacher.php
  classes/external/remove_course_unit_teacher.php
  classes/external/get_course_unit_teachers.php
  classes/external/set_primary_course_unit_teacher.php
)
FAIL=0
for f in "${FILES[@]}"; do
  path="$BASE/$f"
  if [ ! -f "$path" ]; then
    echo "MISSING $f"
    FAIL=1
    continue
  fi
  out=$(php -l "$path" 2>&1)
  ec=$?
  if [ $ec -eq 0 ]; then
    echo "PASS $f :: $out"
  else
    echo "FAIL $f :: $out"
    FAIL=1
  fi
done
echo "---"
echo "SUMMARY_FAIL=$FAIL"
exit $FAIL

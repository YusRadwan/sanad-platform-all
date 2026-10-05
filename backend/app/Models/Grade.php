<?php

declare(strict_types=1);

namespace App\Models;

/**
 * grades — the admin-managed catalog behind AddTeacher.tsx's "التصنيفات"
 * step, and (since the platform's curricula/countries system was removed)
 * the SAME catalog the public student-signup wizard and Admin/AddStudent.tsx
 * read for their grade picker. Entries are copied BY NAME into
 * teachers.grades and students.grade_level.
 *
 * `section_id` scopes every grade to a GradeSection (open decision 10.12) —
 * NOT NULL, restrictOnDelete. `order` is admin-managed manual ordering
 * (up/down in the UI), scoped PER SECTION: new rows default to the end of
 * their own section's list via CatalogType::Grades->creationAttributes().
 *
 * No timestamps: the table has none, matching the other option catalogs
 * (subjects, grade_sections).
 */
class Grade extends BaseModel
{
    protected $table = 'grades';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'section_id',
        'order',
    ];
}

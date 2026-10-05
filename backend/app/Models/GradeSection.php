<?php

declare(strict_types=1);

namespace App\Models;

/**
 * grade_sections — the top-level admin-managed catalog ("أقسام") that
 * groups `grades` rows (see open decision 10.12). Never referenced by name
 * anywhere else in the schema (unlike grades/subjects) — it exists purely
 * to organise the grade picker in AddTeacher.tsx and the student
 * signup/AddStudent grade selects.
 *
 * `order` is admin-managed manual ordering, same as Grade::$order.
 *
 * No timestamps: matches the other option catalogs (grades, subjects).
 */
class GradeSection extends BaseModel
{
    protected $table = 'grade_sections';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'order',
    ];
}

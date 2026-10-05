<?php

declare(strict_types=1);

namespace App\Models;

/**
 * subjects — flat admin-managed option catalog. No timestamps by design.
 */
class Subject extends BaseModel
{
    protected $table = 'subjects';

    public $timestamps = false;

    protected $fillable = ['name'];
}

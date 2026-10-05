<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * content_items.type — matches frontend/src/types/course.ts ContentItem.type.
 *
 * Three of these five have their own detail table keyed 1:1 on the content item
 * (video -> videos, link -> links, note -> notes), and one more has a
 * configuration table (exam -> exams). 'file' has
 * no detail table: it is carried by the `notes` row's file_name/file_path
 * columns, which is why those are nullable there.
 */
enum ContentItemType: string
{
    use HasValues;

    case Video = 'video';
    case Link = 'link';
    case Note = 'note';
    case Exam = 'exam';
    case File = 'file';
}

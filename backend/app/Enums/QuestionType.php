<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * questions.type.
 *
 * Note that the question-bank UI also offers an "image" question kind, but
 * that is NOT a value here: an image question is an ordinary mcq/short_answer
 * row that additionally has questions.image_url set. Adding 'image' to this
 * enum would make the type column mean two different things at once (how it
 * is answered vs. how it is presented).
 */
enum QuestionType: string
{
    use HasValues;

    case Mcq = 'mcq';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';
}

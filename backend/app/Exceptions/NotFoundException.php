<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A 404 whose Arabic message is meant for the end user.
 *
 * Equivalent of backend/app/Core/Exceptions/NotFoundException.php. Extends
 * Symfony's NotFoundHttpException so App\Exceptions\Handler's existing
 * `$e instanceof NotFoundHttpException => 404` mapping keeps working with no
 * new branch, and implements PublicMessage so the message survives instead of
 * being flattened to the generic 'Not found'.
 *
 * WHY THE OPT-OUT MATTERS HERE. Handler genericises every ordinary 404 on
 * purpose: a routing/binding failure's own message embeds the model class and
 * the id that was looked up. This exception exists for 404s whose text IS the
 * contract and is rendered to an admin, e.g.:
 *
 *   'نوع القائمة غير معروف'   unknown /catalogs/{type}
 *   'العنصر غير موجود'         unknown flat catalog id
 *
 * None of them echo caller input, so none of them leak.
 */
final class NotFoundException extends NotFoundHttpException implements PublicMessage
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

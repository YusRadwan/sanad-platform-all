<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\ApiFormRequest;
use App\Rules\SafeUrlValue;

/**
 * PATCH /api/v1/announcements/{id}. Freeze-table row 111.
 *
 * PARTIAL by contract: backend/AnnouncementService::update() builds its write
 * set with six `array_key_exists()` tests, so an ABSENT field is untouched and
 * a PRESENT one is written — including when its value is falsy. That is why
 * every rule below is `sometimes` and why the controller re-tests presence
 * rather than reading defaults.
 */
final class UpdateAnnouncementRequest extends ApiFormRequest
{
    use NormalisesAnnouncementInput;
    use ResolvesAnnouncement;

    /**
     * Ownership FIRST, before any rule runs — so a non-owner's malformed
     * payload is still a 403 and not a 422 that quietly confirms the row
     * exists and is well-formed enough to be worth attacking.
     */
    public function authorize(): bool
    {
        $this->assertManageable();

        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * ╔══════════════════════════════════════════════════════════════╗
             * ║ APPROVED DECISION 4 IS THIS ONE RULE: `sometimes|required`.  ║
             * ║                                                              ║
             * ║ backend/ is INCONSISTENT with itself here and Stage 8 fixes   ║
             * ║ it. POST {title:""} is a 422 ('العنوان والمحتوى مطلوبان'),    ║
             * ║ but PATCH {title:""} passes `array_key_exists` and SILENTLY   ║
             * ║ WRITES an empty title — the same value, rejected on the way   ║
             * ║ in and accepted on the way through. The management screen     ║
             * ║ then renders a nameless card nobody can identify.             ║
             * ║                                                              ║
             * ║ ══ AND `sometimes|string` ALONE WOULD NOT FIX IT. ══          ║
             * ║                                                              ║
             * ║ Validator::presentOrRuleIsImplicit() SKIPS every non-implicit ║
             * ║ rule when the value is a string that trims to ''. So          ║
             * ║ `['sometimes','string']` runs NOTHING on `title: ""` and the  ║
             * ║ bug survives, silently, looking fixed. `required` IS implicit,║
             * ║ so it runs on the blank and rejects it. Stage 4 hit this exact║
             * ║ trap on a different field and App\Rules\SafeUrlValue carries  ║
             * ║ the long version of the argument on its `$implicit` property. ║
             * ║                                                              ║
             * ║ `sometimes` still means the field may be ABSENT — decision 4  ║
             * ║ makes PATCH reject a blank title, not demand one.             ║
             * ╚══════════════════════════════════════════════════════════════╝
             */
            'title' => ['sometimes', 'required', 'string', 'max:190'],
            'content' => ['sometimes', 'required', 'string'],

            // …and link_url is the OPPOSITE case, deliberately: '' is a valid
            // instruction here ("remove the link"), so allowBlank stays true
            // and no `required` is attached.
            'link_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeUrlValue(allowBlank: true)],

            'start_date' => ['sometimes', 'nullable'],
            'end_date' => ['sometimes', 'nullable'],
            'is_published' => ['sometimes', 'boolean'],

            /*
             * `course_id` IS ABSENT FROM THIS LIST ON PURPOSE.
             *
             * backend/'s update() has no course_id branch — a course-scoped
             * announcement cannot be re-pointed at another course. Accepting it
             * here would need the create path's whole 404/403 course-ownership
             * ladder AND an answer to "may you move it to a course you own,
             * away from one you do not?" — a question backend/ never asks and
             * this stage was not asked to invent. An unknown key is simply
             * ignored, exactly as it is today.
             */
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => StoreAnnouncementRequest::TITLE_AND_CONTENT_REQUIRED,
            'content.required' => StoreAnnouncementRequest::TITLE_AND_CONTENT_REQUIRED,
        ]);
    }
}

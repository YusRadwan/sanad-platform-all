<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

/**
 * camelCase -> snake_case, and trimming, done ONCE before any rule runs.
 *
 * ┌── backend/ ACCEPTS BOTH SPELLINGS OF FOUR FIELDS, AND SO DOES THIS. ─────┐
 * │   `$data['course_id'] ?? $data['courseId'] ?? null`                      │
 * │   `$data['linkUrl'] ?? $data['link_url'] ?? null`                        │
 * │   `$data['startDate'] ?? $data['start_date'] ?? null`                    │
 * │   `$data['isPublished'] ?? $data['is_published'] ?? true`                │
 * │                                                                          │
 * │ The frontend sends camelCase; some callers send snake_case. Normalising  │
 * │ in prepareForValidation means the RULES, the controller and the Action   │
 * │ each see exactly one spelling — and, critically, that `sometimes` and    │
 * │ `array_key_exists` semantics are evaluated against a single canonical    │
 * │ key. Two spellings surviving into the rule set would make "was this      │
 * │ field present?" ambiguous, which is the whole basis of PATCH's contract. │
 * │                                                                          │
 * │ NOTE THE PRECEDENCE DIFFERENCE, PORTED VERBATIM: `course_id` wins over   │
 * │ `courseId`, but `linkUrl` wins over `link_url`. backend/ really does     │
 * │ order those two `??` chains differently. It can only matter if a caller  │
 * │ sends both spellings of one field, which no client does; it is copied    │
 * │ rather than tidied so a diff against backend/ stays empty.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ TRIMMING HAPPENS HERE, NOT IN THE ACTION, AND THAT IS WHAT MAKES        ║
 * ║ `title: '   '` A 422 INSTEAD OF A BLANK TITLE.                          ║
 * ║                                                                          ║
 * ║ Laravel's `required` accepts a string of spaces — it is "present". So a  ║
 * ║ trim performed downstream (as backend/ does, inside create()) would let  ║
 * ║ whitespace pass validation and be written as ''. Trimming BEFORE the     ║
 * ║ rules run is what closes that, and it is also what makes decision 4's    ║
 * ║ `sometimes|required` on PATCH mean what it says.                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
trait NormalisesAnnouncementInput
{
    /** camelCase alias => canonical snake_case key, in backend/'s own order. */
    private const ALIASES = [
        'courseId' => 'course_id',
        'linkUrl' => 'link_url',
        'startDate' => 'start_date',
        'endDate' => 'end_date',
        'isPublished' => 'is_published',
    ];

    /** The fields backend/ passes through trim(). */
    private const TRIMMED = ['title', 'content'];

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (self::ALIASES as $camel => $snake) {
            // PRESENCE, not truthiness: `isPublished: false` and
            // `linkUrl: ''` are real instructions and must survive.
            if (!$this->has($snake) && $this->has($camel)) {
                $merge[$snake] = $this->input($camel);
            }
        }

        foreach (self::TRIMMED as $field) {
            $value = $this->input($field);

            if (\is_string($value)) {
                $merge[$field] = trim($value);
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * `link_url` as it should be STORED.
     *
     * SafeUrlValue has already rejected anything unsafe, so the only work left
     * is UrlValidator::nullable()'s one remaining rule: a blank string is not a
     * link, it is the ABSENCE of one. Returning '' would put an empty `href`
     * on the announcement card, which renders as a link to the current page.
     */
    public function normalisedLinkUrl(): ?string
    {
        $value = $this->input('link_url');

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function normalisedStartDate(): ?string
    {
        return $this->normaliseDate($this->input('start_date'));
    }

    public function normalisedEndDate(): ?string
    {
        return $this->normaliseDate($this->input('end_date'));
    }

    /**
     * backend/AnnouncementService::normalizeDate() — strtotime, and a SILENT
     * null on anything unparseable.
     *
     * ┌── THE SILENCE IS PORTED, AND IT IS NOT THE BROADCAST BEHAVIOUR. ─────┐
     * │ An announcement with a garbage startDate stores NULL and answers      │
     * │ 200; a BROADCAST with a garbage start_at answers 422                  │
     * │ ('صيغة التاريخ غير صحيحة'). Same author, same request shape, two      │
     * │ different contracts — because a broadcast's window governs DELIVERY   │
     * │ to real inboxes while an announcement's is display metadata.           │
     * │ Both are backend/'s; neither is changed here. Open decision 8.7.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function normaliseDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}

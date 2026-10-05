<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

use App\Enums\BroadcastAudience;
use App\Http\Requests\ApiFormRequest;
use App\Rules\SafeUrlValue;
use App\Support\BroadcastAudienceResolver;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The SHARED validator for POST and PUT. Port of
 * backend/BroadcastNotificationService::validate() (~lines 198-263).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ PUT IS A **FULL REPLACEMENT**, NOT A PATCH — WHICH IS WHY ONE CLASS      ║
 * ║ SERVES BOTH VERBS.                                                       ║
 * ║                                                                          ║
 * ║ backend/update() calls the SAME validate() as create() and writes every   ║
 * ║ column unconditionally: title, body, link, audience, target_user_ids,     ║
 * ║ start_at, end_at. There is no `array_key_exists` anywhere in it. So a     ║
 * ║ PUT that omits `body` is a 422, not a "leave it alone" — and the verb is  ║
 * ║ PUT rather than PATCH precisely because that is the contract.             ║
 * ║                                                                          ║
 * ║ CONTRAST /announcements, whose PATCH is genuinely partial and needs two   ║
 * ║ separate Form Requests. Two surfaces, two contracts, both ported.         ║
 * ║                                                                          ║
 * ║ `is_active` IS NOT IN THIS LIST. Enable/disable has its own endpoint, and ║
 * ║ backend/update()'s UPDATE statement deliberately omits the column — so an ║
 * ║ admin editing a DISABLED broadcast does not silently re-publish it to     ║
 * ║ every inbox on save.                                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class BroadcastWriteRequest extends ApiFormRequest
{
    public const LINK_TOO_LONG = 'الرابط طويل جداً (الحد الأقصى 500 حرف)';

    public const END_BEFORE_START = 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء';

    public const PICK_ONE_TEACHER = 'يرجى اختيار معلم واحد على الأقل للجمهور المستهدف';

    public const TOO_MANY_TARGETS = 'عدد المستهدفين يتجاوز الحد المسموح';

    public const BAD_TARGET_VALUE = 'قائمة المستهدفين تحتوي على قيمة غير صحيحة';

    public const SPECIFIC_USER_REQUIRED = 'يرجى كتابة اسم أو كنية المستخدم المستهدف بالنظام';

    public const BAD_DATE = 'صيغة التاريخ غير صحيحة';

    /** @var array{start: string, end: ?string}|null */
    private ?array $window = null;

    public function rules(): array
    {
        return [
            // min:3 IS backend/'s here, unlike on /announcements — see
            // StoreAnnouncementRequest for why the asymmetry is preserved.
            'title' => ['required', 'string', 'min:3', 'max:190'],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'audience' => ['required', 'string', 'in:' . implode(',', BroadcastAudience::values())],
            'start_at' => ['required'],
            'end_at' => ['sometimes', 'nullable'],
            'link' => ['sometimes', 'nullable', 'string', new SafeUrlValue(allowBlank: true)],
            'target_user_ids' => ['sometimes', 'nullable', 'array'],
            'specific_user' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * The checks Laravel's rule vocabulary cannot express with backend/'s exact
     * messages: the link length limit, the schedule ordering, and the two
     * audience-conditional target fields.
     *
     * `after` rather than `withValidator`+`sometimes` so that these run only
     * once the basic shape is known good — otherwise `audience` could be an
     * array here and every branch below would have to re-check its type.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateLink($validator);
                $this->validateWindow($validator);
                $this->validateAudienceTargets($validator);
            },
        ];
    }

    /**
     * `max:500` on the column, but the MESSAGE is backend/'s own Arabic string
     * rather than the generic `max.string`, because the admin form renders it
     * next to the field verbatim.
     */
    private function validateLink(Validator $validator): void
    {
        $link = $this->normalisedLink();

        if ($link !== null && mb_strlen($link) > 500) {
            $validator->errors()->add('link', self::LINK_TOO_LONG);
        }
    }

    /**
     * The form posts `datetime-local` values ("2026-07-28T14:30").
     *
     * ┌── AN UNPARSEABLE DATE IS A **422** HERE, AND A SILENT NULL ON ───────┐
     * │ /announcements. Both are backend/'s (open decision 8.7). A broadcast │
     * │ window governs DELIVERY to real inboxes, so a garbage start_at that   │
     * │ silently became NULL would violate the NOT NULL column and 500;       │
     * │ an announcement's dates are display metadata.                         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function validateWindow(Validator $validator): void
    {
        $start = $this->parseDateTime($this->input('start_at'));

        if ($start === null) {
            $validator->errors()->add('start_at', self::BAD_DATE);

            return;
        }

        $rawEnd = $this->input('end_at');
        $end = null;

        if ($rawEnd !== null && $rawEnd !== '') {
            $end = $this->parseDateTime($rawEnd);

            if ($end === null) {
                $validator->errors()->add('end_at', self::BAD_DATE);

                return;
            }

            // `<=`, not `<`: a zero-length window is a broadcast that is never
            // visible, which is a mistake rather than a schedule.
            if ($end <= $start) {
                $validator->errors()->add('end_at', self::END_BEFORE_START);

                return;
            }
        }

        $this->window = ['start' => $start, 'end' => $end];
    }

    private function validateAudienceTargets(Validator $validator): void
    {
        $audience = BroadcastAudience::from((string) $this->input('audience'));

        if ($audience === BroadcastAudience::SelectedTeachers) {
            $raw = $this->input('target_user_ids');

            if (!\is_array($raw) || $raw === []) {
                $validator->errors()->add('target_user_ids', self::PICK_ONE_TEACHER);

                return;
            }

            /*
             * THE CAP IS CHECKED ON THE **RAW** COUNT, BEFORE dedup.
             * backend/ does `count($raw) > MAX` on the incoming array, so 600
             * entries that dedup to 3 is still a 422. Ported: the cap is about
             * the size of the request, not the size of the audience.
             */
            if (\count($raw) > BroadcastAudienceResolver::MAX_SELECTED_TEACHERS) {
                $validator->errors()->add('target_user_ids', self::TOO_MANY_TARGETS);

                return;
            }

            foreach ($raw as $value) {
                // backend/ accepts int as well as string — the picker sends
                // uuids, but the type check is the ported one.
                if (!\is_string($value) && !\is_int($value)) {
                    $validator->errors()->add('target_user_ids', self::BAD_TARGET_VALUE);

                    return;
                }
            }
        }

        if ($audience === BroadcastAudience::SpecificUser) {
            $raw = $this->input('specific_user');

            if (!\is_string($raw) || trim($raw) === '') {
                $validator->errors()->add('specific_user', self::SPECIFIC_USER_REQUIRED);
            }
        }
    }

    // ── accessors the controller reads ────────────────────────────────────

    public function audience(): BroadcastAudience
    {
        return BroadcastAudience::from((string) $this->input('audience'));
    }

    /** @return list<string> deduplicated, order preserved */
    public function targetUserIds(): array
    {
        $raw = $this->input('target_user_ids');

        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $v): string => (string) $v,
            $raw
        )));
    }

    public function specificUser(): ?string
    {
        $raw = $this->input('specific_user');

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    public function startAt(): string
    {
        return $this->validatedWindow()['start'];
    }

    public function endAt(): ?string
    {
        return $this->validatedWindow()['end'];
    }

    /**
     * '' is "no link", exactly as UrlValidator::nullable() means it — the same
     * rule the announcement surface applies to link_url.
     */
    public function normalisedLink(): ?string
    {
        $value = $this->input('link');

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function title(): string
    {
        return trim((string) $this->input('title'));
    }

    public function body(): string
    {
        return trim((string) $this->input('body'));
    }

    /** @return array{start: string, end: ?string} */
    private function validatedWindow(): array
    {
        // Unreachable in a normal request — after() populated it and a failure
        // would already have thrown — but an explicit throw beats a null
        // dereference if the accessor is ever called out of order.
        return $this->window ?? throw ValidationException::withMessages([
            'start_at' => [self::BAD_DATE],
        ]);
    }

    private function parseDateTime(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}

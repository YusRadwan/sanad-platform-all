<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Exceptions\NotFoundException;
use App\Models\Announcement;

/**
 * The 404-then-403 ladder shared by PATCH and DELETE.
 *
 * ┌── ORDER MATTERS AND IS THE PORTED ONE. ──────────────────────────────────┐
 * │ backend/AnnouncementService::update()/delete() both do findById() first  │
 * │ and assertOwns() second, so an UNKNOWN id is 404 for everyone, including │
 * │ a caller who would have been refused anyway. Reversing the two would     │
 * │ turn every unknown id into a 403 and leak nothing useful either way —    │
 * │ but it would change the contract the frontend branches on.               │
 * │                                                                          │
 * │ Note this ladder deliberately does NOT hide existence behind 403 the way │
 * │ NotificationController does. The difference is real: an announcement id  │
 * │ is shared editorial content whose existence is not a secret, and the     │
 * │ management screen distinguishes "deleted underneath you" (404, refresh)  │
 * │ from "not yours" (403, a permissions message).                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
trait ResolvesAnnouncement
{
    public const ANNOUNCEMENT_NOT_FOUND = 'Announcement not found';

    /** Refusal text for a non-owner, verbatim from backend/'s assertOwns(). */
    public const NOT_YOUR_ANNOUNCEMENT = 'Not your announcement';

    private ?Announcement $announcement = null;

    public function announcement(): Announcement
    {
        return $this->announcement ??= Announcement::query()
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException(self::ANNOUNCEMENT_NOT_FOUND);
    }

    /**
     * `manage`, through the Gate — so `Gate::before`'s admin bypass is in play
     * and the platform-announcement (teacher_id NULL) branch is decided in ONE
     * place, App\Policies\AnnouncementPolicy, rather than re-derived per verb.
     */
    private function assertManageable(): void
    {
        if ($this->user()->cannot('manage', $this->announcement())) {
            throw new \App\Exceptions\ForbiddenException(self::NOT_YOUR_ANNOUNCEMENT);
        }
    }
}

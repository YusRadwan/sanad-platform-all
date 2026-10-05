<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE PERMANENT ANCHOR FOR THE Gate::before PROBE. STAGE 8.                ║
 * ║                                                                          ║
 * ║ From Stage 3 to Stage 7 the probe test proved "an admin never enters the ║
 * ║ policy" by pointing at a REAL policy whose body still threw a            ║
 * ║ LogicException placeholder. That worked, and it MOVED THREE TIMES —      ║
 * ║ Section (3) -> Exam (5A) -> Announcement (5B) — because each stage that  ║
 * ║ implemented the current anchor had to re-anchor it. Open decisions       ║
 * ║ 5.7 / 5.9 / 5.16 / 7.16 are that migration, recorded four times.         ║
 * ║                                                                          ║
 * ║ Stage 8 implements AnnouncementPolicy and deletes NotificationPolicy, so ║
 * ║ the technique RUNS OUT: there is no unbuilt policy left to borrow. The   ║
 * ║ fix is not to find one more — it is to stop borrowing.                   ║
 * ║                                                                          ║
 * ║ THIS OBJECT IS NOT A DOMAIN MODEL. It is a plain PHP class:              ║
 * ║   * no Eloquent, no `$table`, no migration, no database row;             ║
 * ║   * nothing in app/ references it, and nothing ever will;                ║
 * ║   * it belongs to no stage, so no stage can graduate it, rename it,      ║
 * ║     implement it, or "fix" the test by pointing it somewhere real.       ║
 * ║                                                                          ║
 * ║ It exists for one reason: Gate resolves a policy BY THE ARGUMENT'S CLASS ║
 * ║ NAME, and a class with a registered policy is all the probe ever needed. ║
 * ║ The assertion was never about announcements, exams or sections.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Registered in Tests\Concerns\RegistersGateProbe, which the base TestCase
 * pulls in, so the mapping is live for every test in the suite.
 */
final class GateProbe
{
}

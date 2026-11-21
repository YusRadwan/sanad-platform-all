<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Teacher\CreateTeacher;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter teachers and courses for the public catalogue.
 *
 * WHY: the redesigned homepage is a catalogue (pick your grade -> see the
 * teachers and courses for it). With an empty catalogue the platform looks
 * unfinished, so the owner asked for a starter set they will then edit or
 * delete from the admin panel like any other record.
 *
 * HOW: teachers go through the SAME CreateTeacher action the admin
 * "إضافة مدرس" screen uses, so every row has exactly the shape a real one has
 * (user + teacher profile, role, audit log). Each account gets a random
 * 32-character password that is never printed or stored anywhere, so nobody
 * can sign in as a starter teacher until an admin resets it from the panel.
 *
 * IDEMPOTENT: re-running skips any teacher whose username already exists and
 * any course whose title already exists for that teacher, so the seeder can be
 * run again safely after a partial failure.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\DemoCatalogSeeder --force
 */
final class DemoCatalogSeeder extends Seeder
{
    /**
     * Subject display name => other spellings that may already exist in the
     * admin-managed catalogue (production has e.g. "فيزياء" without "ال").
     *
     * @var array<string, array<int, string>>
     */
    private const SUBJECTS = [
        'الرياضيات' => ['رياضيات'],
        'اللغة العربية' => [],
        'اللغة الإنجليزية' => [],
        'الفيزياء' => ['فيزياء'],
        'الكيمياء' => ['كيمياء'],
    ];

    /** @var array<int, array{username: string, name: string, subject: string, grades: array<int, string>, bio: string}> */
    private const TEACHERS = [
        [
            'username' => 'ahmed.samir',
            'name' => 'أحمد سمير',
            'subject' => 'الرياضيات',
            'grades' => ['الصف الثاني الإعدادي', 'الصف الثالث الإعدادي', 'الصف الثالث الثانوي'],
            'bio' => 'مدرس رياضيات للمرحلتين الإعدادية والثانوية. بيشرح الجبر والهندسة والتفاضل خطوة بخطوة مع حل نماذج امتحانات.',
        ],
        [
            'username' => 'mona.adel',
            'name' => 'منى عادل',
            'subject' => 'اللغة العربية',
            'grades' => ['الصف الأول الإعدادي', 'الصف الثالث الإعدادي'],
            'bio' => 'مدرسة لغة عربية للمرحلة الإعدادية. النحو والقراءة والنصوص بتدريبات بعد كل درس.',
        ],
        [
            'username' => 'mahmoud.fathy',
            'name' => 'محمود فتحي',
            'subject' => 'الفيزياء',
            'grades' => ['الصف الثاني الثانوي', 'الصف الثالث الثانوي'],
            'bio' => 'مدرس فيزياء للمرحلة الثانوية. شرح بالأمثلة ومراجعات نهائية قبل الامتحانات.',
        ],
        [
            'username' => 'sara.hassan',
            'name' => 'سارة حسن',
            'subject' => 'اللغة الإنجليزية',
            'grades' => ['الصف الثالث الإعدادي', 'الصف الثاني الثانوي'],
            'bio' => 'مدرسة لغة إنجليزية. Grammar وVocabulary وحل امتحانات على كل وحدة.',
        ],
        [
            'username' => 'karim.ali',
            'name' => 'كريم علي',
            'subject' => 'الكيمياء',
            'grades' => ['الصف الأول الثانوي', 'الصف الثالث الثانوي'],
            'bio' => 'مدرس كيمياء للمرحلة الثانوية. شرح مبسط وتدريبات على كل جزء من المنهج.',
        ],
    ];

    /** @var array<int, array{teacher: string, title: string, grade: string, price: int, discount: int|null, description: string}> */
    private const COURSES = [
        ['teacher' => 'ahmed.samir', 'title' => 'الجبر', 'grade' => 'الصف الثاني الإعدادي', 'price' => 180, 'discount' => null, 'description' => 'شرح + حل + اختبار على كل درس'],
        ['teacher' => 'ahmed.samir', 'title' => 'الجبر والهندسة', 'grade' => 'الصف الثالث الإعدادي', 'price' => 260, 'discount' => 220, 'description' => 'شرح + حل + اختبار، ومراجعة قبل امتحان آخر السنة'],
        ['teacher' => 'ahmed.samir', 'title' => 'التفاضل والتكامل', 'grade' => 'الصف الثالث الثانوي', 'price' => 300, 'discount' => null, 'description' => 'شرح + حل نماذج امتحانات الثانوية العامة'],
        ['teacher' => 'mona.adel', 'title' => 'القراءة والنصوص', 'grade' => 'الصف الأول الإعدادي', 'price' => 160, 'discount' => null, 'description' => 'شرح وتدريبات على كل نص'],
        ['teacher' => 'mona.adel', 'title' => 'النحو والقراءة', 'grade' => 'الصف الثالث الإعدادي', 'price' => 200, 'discount' => null, 'description' => 'شرح + تدريبات + امتحان شهري'],
        ['teacher' => 'mahmoud.fathy', 'title' => 'الحركة والقوى', 'grade' => 'الصف الثاني الثانوي', 'price' => 280, 'discount' => null, 'description' => 'شرح كل درس وحل نماذج امتحانات'],
        ['teacher' => 'mahmoud.fathy', 'title' => 'الكهربية التيارية', 'grade' => 'الصف الثالث الثانوي', 'price' => 350, 'discount' => null, 'description' => 'شرح + مراجعة نهائية + امتحانات'],
        ['teacher' => 'sara.hassan', 'title' => 'Units 1–6', 'grade' => 'الصف الثالث الإعدادي', 'price' => 180, 'discount' => null, 'description' => 'Grammar وVocabulary وحل امتحانات'],
        ['teacher' => 'sara.hassan', 'title' => 'الترم الأول', 'grade' => 'الصف الثاني الثانوي', 'price' => 300, 'discount' => 200, 'description' => 'شرح + حل + اختبار'],
        ['teacher' => 'karim.ali', 'title' => 'الباب الأول', 'grade' => 'الصف الأول الثانوي', 'price' => 250, 'discount' => null, 'description' => 'شرح وتدريبات على المنهج'],
        ['teacher' => 'karim.ali', 'title' => 'الكيمياء العضوية', 'grade' => 'الصف الثالث الثانوي', 'price' => 320, 'discount' => null, 'description' => 'شرح مبسط وتدريبات على كل جزء'],
    ];

    public function run(CreateTeacher $createTeacher): void
    {
        $subjectNames = $this->ensureSubjects();
        $teacherIds = [];

        foreach (self::TEACHERS as $row) {
            $existing = User::query()->where('username', $row['username'])->value('id');

            if ($existing !== null) {
                $teacherIds[$row['username']] = (string) $existing;
                $this->command?->line("skip teacher {$row['username']} (exists)");
                continue;
            }

            $subject = $subjectNames[$row['subject']];

            $teacher = $createTeacher([
                'username' => $row['username'],
                'password' => Str::password(32),
                'fullName' => $row['name'],
                'subjects' => [$subject],
                'grades' => $row['grades'],
                'specialization' => $subject . ' - ' . implode('، ', $row['grades']),
                'bio' => $row['bio'],
            ]);

            $teacherIds[$row['username']] = (string) $teacher->getKey();
            $this->command?->info("teacher {$row['username']} created");
        }

        $grades = Grade::query()->pluck('id', 'name');

        foreach (self::COURSES as $row) {
            $teacherId = $teacherIds[$row['teacher']] ?? null;
            $gradeId = $grades[$row['grade']] ?? null;
            $teacherRow = collect(self::TEACHERS)->firstWhere('username', $row['teacher']);
            $subjectId = Subject::query()->where('name', $subjectNames[$teacherRow['subject']])->value('id');

            if ($teacherId === null || $gradeId === null) {
                $this->command?->warn("skip course {$row['title']} (teacher or grade missing)");
                continue;
            }

            $exists = Course::query()
                ->where('teacher_id', $teacherId)
                ->where('title', $row['title'])
                ->exists();

            if ($exists) {
                $this->command?->line("skip course {$row['title']} (exists)");
                continue;
            }

            DB::transaction(function () use ($row, $teacherId, $gradeId, $subjectId): void {
                $course = new Course();
                $course->setAttribute('teacher_id', $teacherId);
                $course->setAttribute('title', $row['title']);
                $course->setAttribute('description', $row['description']);
                $course->setAttribute('price', $row['price']);
                $course->setAttribute('discount_price', $row['discount']);
                $course->setAttribute('grade_id', $gradeId);
                $course->setAttribute('subject_id', $subjectId);
                $course->setAttribute('is_published', true);
                $course->setAttribute('visibility', Course::VISIBILITY_PLATFORM);
                $course->save();
            });

            $this->command?->info("course {$row['title']} created");
        }
    }

    /**
     * Resolve every subject to the name already in the catalogue (any known
     * spelling), creating it only when none exists.
     *
     * @return array<string, string> display name => catalogue name
     */
    private function ensureSubjects(): array
    {
        $resolved = [];

        foreach (self::SUBJECTS as $name => $aliases) {
            $found = Subject::query()->whereIn('name', array_merge([$name], $aliases))
                ->orderByRaw('name = ? desc', [$name])
                ->value('name');

            if ($found === null) {
                Subject::query()->create(['name' => $name]);
                $found = $name;
            }

            $resolved[$name] = (string) $found;
        }

        return $resolved;
    }
}

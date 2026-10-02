<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Hris\Sync\SyncEmployees;
use App\Models\Course;
use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    // NB: deliberately NOT using WithoutModelEvents — the BelongsToTenant hook
    // that stamps tenant_id fires on the `creating` model event.

    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo'],
            // The timezone sets when the twice-daily leave sync runs for this tenant.
            ['name' => 'Demo Organisation', 'timezone' => 'Africa/Lagos', 'hris_adapter' => 'mock'],
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@cipherlearn.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Ngozi Adeyemi',
                'password' => Hash::make('password'),
                'auth_provider' => 'local',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Everything below runs inside the tenant context so tenant_id is stamped
        // automatically AND the permission team id (see TenancyTeamResolver) is
        // the demo tenant — which is what scopes the roles created here.
        app(Tenancy::class)->runFor($tenant, function () use ($admin, $tenant) {
            // Roles + permissions first, so the admin can be granted one.
            $this->call(RolesAndPermissionsSeeder::class);
            $admin->assignRole('Tenant Admin');

            // The admin is a person too. Represent them as a manually-added
            // employee (external_id null, so the HR sync leaves them alone) linked
            // to the login — so "LMS Admins" shows an elevated employee, not a
            // floating account. This mirrors how a real admin is a member of staff.
            Employee::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email' => 'admin@cipherlearn.test'],
                [
                    'user_id' => $admin->id,
                    'first_name' => 'Ngozi',
                    'last_name' => 'Adeyemi',
                    'department' => 'Learning & Development',
                    'job_title' => 'L&D Lead',
                    'location' => 'Lagos',
                    'status' => 'active',
                ],
            );

            foreach ($this->catalogue() as $entry) {
                $this->seedCourse($entry);
            }

            $this->seedLearningPath();
        });

        // Populate people the same way production does — by syncing from the HR
        // system — rather than with a factory. The demo tenant uses the mock
        // adapter, so this yields the deterministic ~45-person org, and it means
        // the seeded state is one the sync can reproduce exactly. Idempotent, so
        // re-seeding neither duplicates nor churns anyone.
        app(SyncEmployees::class)->forTenant($tenant);
    }

    /**
     * A demo learning path — a named, ordered bundle of existing courses — so the
     * paths surfaces have real data. Idempotent on the path name.
     */
    private function seedLearningPath(): void
    {
        $courses = Course::query()
            ->whereIn('slug', [
                Str::slug('Consultative Selling'),
                Str::slug('Debt Collections Fundamentals'),
                Str::slug('Financial Compliance & AML'),
            ])
            ->get();

        if ($courses->isEmpty()) {
            return;
        }

        $path = LearningPath::firstOrCreate(
            ['name' => 'Commercial Onboarding'],
            ['description' => 'A ready-made curriculum for new commercial hires: selling, collections and compliance, in order.'],
        );

        if ($path->courses()->exists()) {
            return; // idempotent — already linked
        }

        $path->courses()->attach(
            $courses->values()->mapWithKeys(fn (Course $c, int $i): array => [$c->id => ['position' => $i + 1]])->all(),
        );
    }

    /**
     * @param  array{title: string, summary: string, tags: list<string>, recert_months: int|null, lessons: list<string>, questions: list<array{prompt: string, options: array<string, string>, correct: string}>}  $entry
     */
    private function seedCourse(array $entry): void
    {
        $course = Course::firstOrCreate(
            ['slug' => Str::slug($entry['title'])],
            [
                'title' => $entry['title'],
                'summary' => $entry['summary'],
                'tags' => $entry['tags'],
                'pass_mark' => 70,
                'max_attempts' => 3,
                'recert_months' => $entry['recert_months'],
                'estimated_minutes' => count($entry['lessons']) * 12,
                'status' => 'published',
            ],
        );

        if ($course->lessons()->exists()) {
            return; // idempotent — already seeded
        }

        foreach ($entry['lessons'] as $i => $title) {
            Lesson::create([
                'course_id' => $course->id,
                'title' => $title,
                'content' => $this->lessonBody($title, $entry['title']),
                'position' => $i + 1,
                'estimated_minutes' => 12,
                'file_size_bytes' => random_int(400_000, 2_500_000),
            ]);
        }

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'title' => 'Assessment',
        ]);

        foreach ($entry['questions'] as $i => $q) {
            Question::create([
                'quiz_id' => $quiz->id,
                'prompt' => $q['prompt'],
                'type' => 'single',
                'options' => collect($q['options'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
                'correct_keys' => [$q['correct']],
                'points' => 1,
                'position' => $i + 1,
            ]);
        }
    }

    /**
     * A short, readable module body for a lesson — text-first (no autoplay media,
     * light on metered data) so the course player has real content to render, not
     * a one-line placeholder. Deterministic and derived from the titles, which is
     * plenty for a demo/seed; authored courses supply their own content.
     */
    private function lessonBody(string $lessonTitle, string $courseTitle): string
    {
        return implode("\n\n", [
            "This module of {$courseTitle} covers {$lessonTitle}.",
            'Work through the material below, then mark the lesson complete to '
                .'continue. Each module builds on the previous one; once every '
                .'module is done, the assessment unlocks.',
            "What you'll take away:\n"
                ."- A practical framing of {$lessonTitle} you can apply on the job.\n"
                ."- A worked example that shows it in context.\n"
                .'- A short checklist to put it into practice this week.',
            'When you are ready, mark this lesson complete.',
        ]);
    }

    /**
     * A small, realistic catalogue spanning the departments the product targets.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            [
                'title' => 'Debt Collections Fundamentals',
                'summary' => 'Recovering overdue balances while keeping the customer relationship intact.',
                'tags' => ['collections', 'finance'],
                'recert_months' => null,
                'lessons' => ['The collections mindset', 'Structuring a payment conversation', 'Handling objections', 'Compliance guardrails'],
                'questions' => [
                    ['prompt' => 'What should anchor a collections call?', 'options' => ['a' => 'A repayment plan', 'b' => 'A threat', 'c' => 'Silence', 'd' => 'An apology'], 'correct' => 'a'],
                    ['prompt' => 'When is escalation appropriate?', 'options' => ['a' => 'Immediately', 'b' => 'After documented attempts', 'c' => 'Never', 'd' => 'On the first missed reply'], 'correct' => 'b'],
                    ['prompt' => 'Collections communication must always be…', 'options' => ['a' => 'Aggressive', 'b' => 'Compliant and respectful', 'c' => 'Anonymous', 'd' => 'Verbal only'], 'correct' => 'b'],
                ],
            ],
            [
                'title' => 'Consultative Selling',
                'summary' => 'Selling by diagnosing needs rather than pushing product.',
                'tags' => ['sales', 'communication'],
                'recert_months' => null,
                'lessons' => ['Discovery questions', 'Mapping needs to value', 'Handling price'],
                'questions' => [
                    ['prompt' => 'Consultative selling starts with…', 'options' => ['a' => 'A pitch', 'b' => 'Discovery', 'c' => 'A discount', 'd' => 'A contract'], 'correct' => 'b'],
                    ['prompt' => 'Value is best framed in terms of…', 'options' => ['a' => 'Features', 'b' => 'The buyer’s outcome', 'c' => 'Your quota', 'd' => 'Competitor prices'], 'correct' => 'b'],
                ],
            ],
            [
                'title' => 'Workplace Safety Essentials',
                'summary' => 'Core health & safety practices for on-site and field teams.',
                'tags' => ['safety', 'compliance'],
                'recert_months' => 12,
                'lessons' => ['Hazard identification', 'Personal protective equipment', 'Incident reporting'],
                'questions' => [
                    ['prompt' => 'A near-miss should be…', 'options' => ['a' => 'Ignored', 'b' => 'Reported', 'c' => 'Hidden', 'd' => 'Punished'], 'correct' => 'b'],
                    ['prompt' => 'PPE is…', 'options' => ['a' => 'Optional', 'b' => 'A last line of defence', 'c' => 'Only for visitors', 'd' => 'Decorative'], 'correct' => 'b'],
                ],
            ],
            [
                'title' => 'Financial Compliance & AML',
                'summary' => 'Anti-money-laundering obligations and red-flag detection.',
                'tags' => ['compliance', 'finance'],
                'recert_months' => 12,
                'lessons' => ['What AML protects', 'Knowing your customer', 'Reporting suspicious activity'],
                'questions' => [
                    ['prompt' => 'KYC stands for…', 'options' => ['a' => 'Know Your Customer', 'b' => 'Keep Your Cash', 'c' => 'Key Yield Control', 'd' => 'Known Yearly Costs'], 'correct' => 'a'],
                    ['prompt' => 'A suspicious transaction should be…', 'options' => ['a' => 'Approved quickly', 'b' => 'Reported internally', 'c' => 'Deleted', 'd' => 'Ignored if small'], 'correct' => 'b'],
                ],
            ],
            [
                'title' => 'Leading Remote Teams',
                'summary' => 'Managing distributed teams across time zones and cultures.',
                'tags' => ['leadership', 'communication'],
                'recert_months' => null,
                'lessons' => ['Async communication', 'Building trust remotely', 'Running effective 1:1s'],
                'questions' => [
                    ['prompt' => 'Async communication works best when it is…', 'options' => ['a' => 'Vague', 'b' => 'Clear and written down', 'c' => 'Verbal only', 'd' => 'Rare'], 'correct' => 'b'],
                    ['prompt' => 'Remote trust is built primarily through…', 'options' => ['a' => 'Surveillance', 'b' => 'Consistency and follow-through', 'c' => 'Longer hours', 'd' => 'More meetings'], 'correct' => 'b'],
                ],
            ],
        ];
    }
}

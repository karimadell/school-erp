<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * Finance UAT corrective (P0) — live-registration-safe Student identity
 * matching, used only to WARN an operator that a candidate may already
 * exist before Quick Registration creates a new Student. Never binds,
 * merges, or reuses a Student on its own; every candidate this class
 * returns is a suggestion for a human to confirm or dismiss.
 *
 * Deliberately NOT MasterStudentImportService: that class is a one-time,
 * bespoke historical-roster reconciliation tool (it contains hard-coded
 * special cases for specific historical students' names) — unsafe to call
 * from live, interactive registration. Only its proven normalization shape
 * (trim, collapse whitespace, lowercase, Russian ё→е) is reused here, as a
 * fresh, generic implementation with no historical special-casing.
 *
 * Matching is exact-normalized-name only — no fuzzy/phonetic/similarity
 * scoring. A candidate list is fetched and compared in PHP (mirroring
 * MasterStudentImportService's own approach of grouping by a normalized key
 * in memory rather than in SQL) because the normalization itself (lowercase
 * + ё→е collapse) isn't expressible identically across every DB driver this
 * project runs on; this project's own student counts make this safe.
 */
class StudentIdentityResolver
{
    public const STRENGTH_STRONG = 'strong';

    public const STRENGTH_POSSIBLE = 'possible';

    /**
     * How long an issued confirmation token remains valid. Generous enough
     * to survive a single registration attempt (including a validation-
     * error round trip), short enough that a token can't realistically be
     * reused across unrelated sessions.
     */
    private const TOKEN_TTL_SECONDS = 3600;

    /**
     * Normalizes one Russian name part exactly like Student's own
     * normalizeRussianNamePart() (trim + collapse whitespace), plus the two
     * additional steps MasterStudentImportService::key() already proved
     * necessary for real duplicate detection: Unicode lowercase and
     * Russian ё→е folding (е/ё are used interchangeably in real data).
     */
    public function normalizePart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $collapsed = Student::normalizeRussianNamePart($value);
        if ($collapsed === null) {
            return null;
        }

        return mb_strtolower(str_replace('ё', 'е', $collapsed));
    }

    /** The deterministic full-name identity key this whole resolver matches on. */
    public function normalizedNameKey(?string $lastNameRu, ?string $firstNameRu, ?string $patronymicRu): string
    {
        return collect([$lastNameRu, $firstNameRu, $patronymicRu])
            ->map(fn (?string $part) => $this->normalizePart($part))
            ->filter()
            ->implode(' ');
    }

    private function normalizePhone(?string $phone): ?string
    {
        $trimmed = trim((string) $phone);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Every existing Student whose normalized full name exactly matches the
     * submitted one — structured RU name fields when the candidate has
     * them, the legacy `name` column otherwise (2026_09 UAT data shows the
     * overwhelming majority of real Student rows only ever populate `name`
     * — see the discovery pass this corrective is based on). Never filtered
     * by status, current-year Enrollment, or any archival concept: this
     * project has none of those on Student, and excluding a former/
     * historical/no-current-enrollment Student here would defeat the whole
     * purpose of this check.
     *
     * @return Collection<int, array{student_id:int, name:string, phone:?string, strength:string, latest_enrollment_year:?string, latest_enrollment_class:?string, has_current_year_enrollment:bool}>
     */
    public function findCandidates(?string $lastNameRu, ?string $firstNameRu, ?string $patronymicRu, ?string $phone): Collection
    {
        $submittedKey = $this->normalizedNameKey($lastNameRu, $firstNameRu, $patronymicRu);
        if ($submittedKey === '') {
            return collect();
        }
        $submittedPhone = $this->normalizePhone($phone);
        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        return Student::query()
            ->with(['enrollments' => fn ($query) => $query
                ->with(['academicYear:id,name,start_date', 'schoolClass:id,name_ru'])
                ->orderByDesc('academic_year_id')])
            ->get(['id', 'name', 'last_name_ru', 'first_name_ru', 'patronymic_ru', 'phone'])
            ->filter(function (Student $candidate) use ($submittedKey) {
                $candidateKey = filled($candidate->last_name_ru) && filled($candidate->first_name_ru)
                    ? $this->normalizedNameKey($candidate->last_name_ru, $candidate->first_name_ru, $candidate->patronymic_ru)
                    : $this->normalizePart($candidate->name);

                return $candidateKey === $submittedKey;
            })
            ->map(function (Student $candidate) use ($submittedPhone, $activeYearId) {
                $candidatePhone = $this->normalizePhone($candidate->phone);
                $strong = $candidatePhone !== null && $submittedPhone !== null && $candidatePhone === $submittedPhone;
                $latest = $candidate->enrollments->first();

                return [
                    'student_id' => $candidate->id,
                    'name' => filled($candidate->last_name_ru) && filled($candidate->first_name_ru)
                        ? $candidate->russianFullName()
                        : (string) $candidate->name,
                    'phone' => $candidate->phone,
                    'strength' => $strong ? self::STRENGTH_STRONG : self::STRENGTH_POSSIBLE,
                    'latest_enrollment_year' => $latest?->academicYear?->name,
                    'latest_enrollment_class' => $latest?->schoolClass?->name_ru,
                    'has_current_year_enrollment' => $activeYearId !== null
                        && $candidate->enrollments->contains(fn ($enrollment) => $enrollment->academic_year_id === $activeYearId),
                ];
            })
            ->values();
    }

    /**
     * A tamper-proof, stateless proof that an operator already reviewed the
     * candidates for THIS EXACT submitted identity and chose to continue.
     * Deliberately encodes the normalized identity itself (not just an
     * opaque id) — if the operator changes the submitted name or phone
     * after receiving this token, verifyConfirmation() below can never
     * match it, forcing a fresh candidate check. No new database table:
     * Laravel's own APP_KEY-backed encryption is the entire trust
     * boundary, exactly the kind of "Laravel-native, server-verifiable"
     * mechanism this corrective calls for.
     */
    public function issueConfirmationToken(?string $lastNameRu, ?string $firstNameRu, ?string $patronymicRu, ?string $phone): string
    {
        return Crypt::encryptString(json_encode([
            'name_key' => $this->normalizedNameKey($lastNameRu, $firstNameRu, $patronymicRu),
            'phone' => $this->normalizePhone($phone),
            'issued_at' => now()->timestamp,
        ]));
    }

    /**
     * True only when $token was genuinely issued by issueConfirmationToken()
     * (decrypts cleanly under this app's own key), is not expired, and its
     * encoded identity still matches the identity being submitted right
     * now. A generic bypass flag could never satisfy this — there is no
     * value an operator or a script could hand-construct that verifies.
     */
    public function confirmationMatchesIdentity(?string $token, ?string $lastNameRu, ?string $firstNameRu, ?string $patronymicRu, ?string $phone): bool
    {
        if (! $token) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return false;
        }

        if (! is_array($payload) || ! isset($payload['name_key'], $payload['issued_at']) || ! array_key_exists('phone', $payload)) {
            return false;
        }

        if (now()->timestamp - (int) $payload['issued_at'] > self::TOKEN_TTL_SECONDS) {
            return false;
        }

        return $payload['name_key'] === $this->normalizedNameKey($lastNameRu, $firstNameRu, $patronymicRu)
            && $payload['phone'] === $this->normalizePhone($phone);
    }
}

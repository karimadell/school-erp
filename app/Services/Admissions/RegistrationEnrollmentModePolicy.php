<?php

namespace App\Services\Admissions;

use App\Models\EnrollmentMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RegistrationEnrollmentModePolicy
{
    /** @return array<int, string> */
    public function codes(): array
    {
        return [
            EnrollmentMode::FULL_TIME,
            EnrollmentMode::FAMILY,
            EnrollmentMode::EXTERNAL,
            EnrollmentMode::NO_ENROLLMENT,
        ];
    }

    /** @return Collection<int, EnrollmentMode> */
    public function all(): Collection
    {
        return EnrollmentMode::query()
            ->whereIn('code', $this->codes())
            ->ordered()
            ->get();
    }

    public function configurationError(): ?string
    {
        $modes = $this->all();
        foreach ($this->codes() as $code) {
            $count = $modes->where('code', $code)->count();
            if ($count !== 1) {
                return "Формы обучения не настроены. Ожидалась ровно одна каноническая форма с кодом {$code}; найдено: {$count}.";
            }
        }

        return null;
    }

    public function assertConfigured(): void
    {
        if ($error = $this->configurationError()) {
            throw ValidationException::withMessages([
                'enrollment_mode_id' => $error,
            ]);
        }
    }

    public function resolve(int $id): EnrollmentMode
    {
        $this->assertConfigured();

        $mode = EnrollmentMode::query()
            ->whereKey($id)
            ->whereIn('code', $this->codes())
            ->first();

        if (! $mode) {
            throw ValidationException::withMessages([
                'enrollment_mode_id' => 'Выбранная форма обучения недоступна для регистрации.',
            ]);
        }

        return $mode;
    }
}

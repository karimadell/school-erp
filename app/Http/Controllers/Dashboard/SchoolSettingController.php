<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSchoolSettingRequest;
use App\Models\AcademicYear;
use App\Models\SchoolSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SchoolSettingController extends Controller
{
    public function edit(): View
    {
        $settings = SchoolSetting::current();
        $this->authorize('update', $settings);

        return view('dashboard.settings.school', [
            'settings' => $settings,
            'academicYears' => AcademicYear::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function update(UpdateSchoolSettingRequest $request): RedirectResponse
    {
        $settings = SchoolSetting::current();
        $data = $request->safe()->except(['logo', 'printing_logo', 'stamp', 'director_signature']);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $this->storeBrandingImage($request->file('logo'), 'logo');
            $data['printing_logo_path'] = $data['logo_path'];
        }

        foreach (['printing_logo', 'stamp', 'director_signature'] as $field) {
            if ($request->hasFile($field)) {
                $data[$field.'_path'] = $this->storeBrandingImage($request->file($field), $field);
            }
        }

        $settings->update($data);

        return back()->with('success', 'Настройки школы сохранены. Новое оформление применяется ко всем документам.');
    }

    private function storeBrandingImage(UploadedFile $file, string $field): string
    {
        $path = $file->store('branding', config('filesystems.uploads.public'));

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                $field => 'Не удалось сохранить изображение. Проверьте доступ к хранилищу и повторите попытку.',
            ]);
        }

        return $path;
    }
}

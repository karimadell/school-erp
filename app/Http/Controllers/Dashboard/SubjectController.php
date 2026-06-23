<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SubjectController extends Controller
{
    public function index(): View
    {
        $subjects = Subject::orderBy('name_ru')->get();

        return view('dashboard.subjects.index', compact('subjects'));
    }

    public function create(): View
    {
        return view('dashboard.subjects.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:subjects,code'],
            'name_ru' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if (empty($data['code'])) {
            $data['code'] = $this->generateCode($data['name_ru']);
        }

        Subject::create($data);

        return redirect()
            ->route('dashboard.subjects.index')
            ->with('success', __('subjects.created_success'));
    }

    public function edit(Subject $subject): View
    {
        return view('dashboard.subjects.edit', compact('subject'));
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:subjects,code,' . $subject->id],
            'name_ru' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if (empty($data['code'])) {
            $data['code'] = $this->generateCode($data['name_ru'], $subject->id);
        }

        $subject->update($data);

        return redirect()
            ->route('dashboard.subjects.index')
            ->with('success', __('subjects.updated_success'));
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        $subject->delete();

        return redirect()
            ->route('dashboard.subjects.index')
            ->with('success', __('subjects.deleted_success'));
    }

    private function generateCode(string $name, ?int $ignoreId = null): string
    {
        $prefix = mb_strtoupper(mb_substr(preg_replace('/\s+/u', '', $name), 0, 3));

        if (!$prefix) {
            $prefix = 'SUB';
        }

        $prefix = preg_replace('/[^A-ZА-Я0-9]/u', '', $prefix) ?: 'SUB';

        $count = Subject::when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->count() + 1;

        do {
            $code = $prefix . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            $exists = Subject::where('code', $code)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            $count++;
        } while ($exists);

        return $code;
    }
}
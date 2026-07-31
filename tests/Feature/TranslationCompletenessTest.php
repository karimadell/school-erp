<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard for the Batch 1 langPath fix: app()->langPath() must
 * resolve to lang/ (not resources/lang/, which silently made every custom
 * translation file dead — see bootstrap/app.php). This walks every key in
 * every lang/ru/*.php file (Russian is the primary, required-complete
 * locale; en/ar are secondary and intentionally not asserted here) and
 * every lang/ru.json entry, and fails if any of them resolve back to the
 * raw key/string instead of real text — Laravel's __() returns the key
 * unchanged whenever no translation is found.
 */
class TranslationCompletenessTest extends TestCase
{
    public function test_every_key_in_every_ru_lang_file_resolves_to_real_text(): void
    {
        app()->setLocale('ru');

        $langPath = lang_path('ru');
        $files = glob($langPath . '/*.php');

        $this->assertNotEmpty($files, 'Expected to find translation files under lang/ru.');

        foreach ($files as $file) {
            $namespace = basename($file, '.php');
            $translations = require $file;

            $this->assertIsArray(
                $translations,
                "lang/ru/{$namespace}.php must return an array (a parse error or stray content would break every key in this file)."
            );

            foreach ($this->flatten($translations) as $key => $value) {
                $fullKey = "{$namespace}.{$key}";
                $resolved = __($fullKey);

                $this->assertNotSame(
                    $fullKey,
                    $resolved,
                    "Translation key [{$fullKey}] resolved to itself — it is missing or unreachable (check lang path / file placement)."
                );
            }
        }
    }

    public function test_every_ru_json_string_translation_resolves(): void
    {
        app()->setLocale('ru');

        $jsonPath = lang_path('ru.json');

        $this->assertFileExists($jsonPath, 'Expected lang/ru.json (used for error-page strings) to exist.');

        $strings = json_decode(file_get_contents($jsonPath), true);

        $this->assertIsArray($strings);
        $this->assertNotEmpty($strings);

        // Keys intentionally translated to the same string — e.g. "Email" is
        // a common loanword left as-is in Russian UI copy, not a missing
        // translation.
        $intentionallyIdentical = ['Email'];

        foreach (array_keys($strings) as $englishString) {
            if (in_array($englishString, $intentionallyIdentical, true)) {
                continue;
            }

            $resolved = __($englishString);

            $this->assertNotSame(
                $englishString,
                $resolved,
                "JSON translation for [{$englishString}] resolved to itself — it is missing or unreachable."
            );
        }
    }

    public function test_lang_path_resolves_to_the_base_lang_directory_not_resources_lang(): void
    {
        $this->assertSame(lang_path(), app()->langPath());
        $this->assertStringEndsNotWith('resources/lang', app()->langPath());
    }

    /**
     * @param  array<array-key, mixed>  $translations
     * @return array<string, mixed>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        $result = [];

        foreach ($translations as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $result += $this->flatten($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}

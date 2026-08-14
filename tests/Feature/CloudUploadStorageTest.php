<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudUploadStorageTest extends TestCase
{
    public function test_public_and_private_upload_disks_are_independently_configurable(): void
    {
        Storage::fake('cloud-public-test');
        Storage::fake('cloud-private-test');
        config()->set('filesystems.uploads.public', 'cloud-public-test');
        config()->set('filesystems.uploads.private', 'cloud-private-test');

        $photo = UploadedFile::fake()->image('student.jpg');
        $document = UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf');
        $photoPath = $photo->store('students/photos', config('filesystems.uploads.public'));
        $documentPath = $document->store('students/1/documents', config('filesystems.uploads.private'));

        Storage::disk('cloud-public-test')->assertExists($photoPath);
        Storage::disk('cloud-public-test')->assertMissing($documentPath);
        Storage::disk('cloud-private-test')->assertExists($documentPath);
    }

    public function test_school_setting_uses_configured_public_upload_disk(): void
    {
        Storage::fake('cloud-public-test');
        config()->set('filesystems.uploads.public', 'cloud-public-test');
        Storage::disk('cloud-public-test')->put('branding/logo.png', 'fake-image');

        $settings = new SchoolSetting(['logo_path' => 'branding/logo.png']);

        $this->assertNotNull($settings->logoUrl());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileImageUploadTest extends TestCase
{
    private ?User $testUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $unique = Str::lower(Str::random(10));
        $this->testUser = User::create([
            'first_name' => 'Profile',
            'last_name' => 'Tester',
            'email' => "profile.tester.{$unique}@stockmate.test",
            'phone' => '92300' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $this->testUser->assignRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->testUser) {
            $this->testUser->clearMediaCollection(User::PATH);
            $this->testUser->forceDelete();
        }

        parent::tearDown();
    }

    public function test_user_can_upload_replace_and_fetch_profile_image_safely(): void
    {
        $jpg = UploadedFile::fake()->image('my unsafe ../avatar.jpg', 200, 200)->size(120);

        $response = $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $jpg,
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $firstUrl = $response->json('data.attributes.image');
        $this->assertNotEmpty($firstUrl);
        $this->assertStringContainsString('/uploads/user_image/', $firstUrl);
        $this->assertStringNotContainsString('/storage/storage/', $firstUrl);
        $this->assertStringNotContainsString('..', $firstUrl);

        $this->testUser->refresh();
        $firstMedia = $this->testUser->getMedia(User::PATH)->first();
        $this->assertNotNull($firstMedia);
        $this->assertStringStartsWith('avatar_' . $this->testUser->id . '_', $firstMedia->file_name);
        $this->assertFileExists($firstMedia->getPath());
        $firstPath = $firstMedia->getPath();

        // Verify edit-profile returns the exact same URL
        $editResp = $this->actingAs($this->testUser, 'sanctum')
            ->getJson('/api/edit-profile');
        $editResp->assertOk();
        $this->assertSame($firstUrl, $editResp->json('data.attributes.image'));

        // Replace with a second image (PNG)
        $png = UploadedFile::fake()->image('second_avatar.png', 240, 240)->size(150);
        $replaceResp = $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $png,
            ], ['Accept' => 'application/json']);

        $replaceResp->assertOk();
        $secondUrl = $replaceResp->json('data.attributes.image');
        $this->assertNotEmpty($secondUrl);
        $this->assertNotSame($firstUrl, $secondUrl);

        $this->testUser->refresh();
        $allMedia = $this->testUser->getMedia(User::PATH);
        $this->assertCount(1, $allMedia);
        $this->assertFileExists($allMedia->first()->getPath());
        $this->assertFileDoesNotExist($firstPath);
    }

    public function test_invalid_or_oversized_upload_is_rejected_without_losing_previous_image(): void
    {
        $valid = UploadedFile::fake()->image('valid.jpg', 160, 160)->size(100);
        $initialResp = $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $valid,
            ], ['Accept' => 'application/json']);
        $initialResp->assertOk();
        $originalUrl = $initialResp->json('data.attributes.image');
        $this->assertNotEmpty($originalUrl);

        // 1. Reject unsafe SVG / non-image file
        $svg = UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml');
        $svgResp = $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $svg,
            ], ['Accept' => 'application/json']);
        $svgResp->assertStatus(422);
        $this->assertNotEmpty($svgResp->json('message'));

        // 2. Reject oversized file (> 2048 KB)
        $oversized = UploadedFile::fake()->image('huge.jpg', 300, 300)->size(3000);
        $sizeResp = $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $oversized,
            ], ['Accept' => 'application/json']);
        $sizeResp->assertStatus(422);
        $this->assertNotEmpty($sizeResp->json('message'));

        // Previous image is still intact
        $this->testUser->refresh();
        $this->assertSame($originalUrl, $this->testUser->image_url);
    }

    public function test_missing_file_on_disk_returns_empty_string_instead_of_broken_url(): void
    {
        $valid = UploadedFile::fake()->image('temp.jpg', 120, 120)->size(80);
        $this->actingAs($this->testUser, 'sanctum')
            ->post('/api/update-profile', [
                'first_name' => 'Profile',
                'last_name' => 'Tester',
                'email' => $this->testUser->email,
                'phone' => $this->testUser->phone,
                'image' => $valid,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->testUser->refresh();
        $media = $this->testUser->getMedia(User::PATH)->first();
        $this->assertNotNull($media);
        @unlink($media->getPath());

        $this->testUser->unsetRelation('media');
        $this->assertSame('', $this->testUser->image_url);
    }

    public function test_unauthenticated_profile_update_is_rejected(): void
    {
        $this->postJson('/api/update-profile', [
            'first_name' => 'Hacker',
            'last_name' => 'User',
            'email' => 'hacker@example.com',
            'phone' => '923001112223',
        ])->assertStatus(401);
    }
}

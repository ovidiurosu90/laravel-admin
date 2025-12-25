<?php

namespace Tests\Feature;

use App\Models\Theme;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;

class ThemesTest extends TestCase
{
    // use RefreshDatabase;
    use WithoutMiddleware;

    /**
     * Create a new theme test.
     *
     * @return Theme
     */
    public function testCreateNewTheme()
    {
        $faker = \Faker\Factory::create();
        $themeName = $faker->domainWord;
        $themeUrl = $faker->url;

        $theme = Theme::create([
            'name'          => $themeName,
            'link'          => $themeUrl,
            'notes'         => 'Test Default Theme.',
            'status'        => 1,
            'taggable_id'   => 0,
            'taggable_type' => 'theme',
        ]);
        $theme->taggable_id = $theme->id;
        $theme->save();

        $createdTheme = Theme::where('name', $themeName)->first();
        $this->assertEquals($themeUrl, $createdTheme->link);
        $this->assertEquals($createdTheme->id, $createdTheme->taggable_id);

        return $createdTheme;
    }

    /**
     * Test updating an existing theme.
     *
     * @return Theme
     */
    #[Depends('testCreateNewTheme')]
    public function testUpdateTheme(Theme $theme)
    {
        // Update the theme
        $updatedName = 'Updated Theme';
        $updatedUrl = 'https://updated.theme.com';
        $updatedNotes = 'Updated notes for testing';

        $theme->update([
            'name'   => $updatedName,
            'link'   => $updatedUrl,
            'notes'  => $updatedNotes,
            'status' => 0,
        ]);

        // Verify the update
        $updatedTheme = Theme::find($theme->id);
        $this->assertEquals($updatedName, $updatedTheme->name);
        $this->assertEquals($updatedUrl, $updatedTheme->link);
        $this->assertEquals($updatedNotes, $updatedTheme->notes);
        $this->assertEquals(0, $updatedTheme->status);

        return $updatedTheme;
    }

    /**
     * Test force deleting a theme (permanent deletion).
     *
     * @return void
     */
    #[Depends('testUpdateTheme')]
    public function testForceDeleteTheme(Theme $theme)
    {
        $themeId = $theme->id;

        // Force delete the theme
        $theme->forceDelete();

        // Verify it's completely gone from database
        $this->assertNull(Theme::withTrashed()->find($themeId));
    }
}

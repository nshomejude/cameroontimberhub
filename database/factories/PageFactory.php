<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(4);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'h1' => null,
            'meta_description' => $this->faker->sentence(),
            'template' => 'static',
            'is_published' => true,
            'data' => null,
            'schema_json' => null,
            'canonical_url' => null,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }

    public function landing(): static
    {
        return $this->state(['template' => 'landing']);
    }

    public function legal(): static
    {
        return $this->state(['template' => 'legal']);
    }
}

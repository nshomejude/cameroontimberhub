<?php

namespace Database\Factories;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = rtrim($this->faker->unique()->sentence(6), '.');

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'title' => $title,
            'excerpt' => $this->faker->paragraph(),
            'body' => "## Overview\n\n".$this->faker->paragraph(6)."\n\n## Practical notes\n\n".$this->faker->paragraph(6),
            'category' => $this->faker->randomElement(ArticleCategory::cases()),
            'meta_title' => $title,
            'meta_description' => $this->faker->sentence(14),
            'keywords' => ['cameroon timber', 'export'],
            'faqs' => [],
            'sources' => [],
            'reading_minutes' => $this->faker->numberBetween(3, 12),
            'status' => ArticleStatus::Published,
            'published_at' => now()->subDays($this->faker->numberBetween(1, 400)),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ArticleStatus::Draft, 'published_at' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ArticleStatus::Archived]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function category(ArticleCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function withFaqs(): static
    {
        return $this->state(fn (): array => [
            'faqs' => [
                ['question' => 'Is Cameroon timber legal to import into the EU?', 'answer' => 'Yes, when it is covered by valid legality documentation and the importer completes EUDR due diligence.'],
                ['question' => 'What is the minimum order quantity?', 'answer' => 'Most exporters quote from one 20ft container upwards.'],
            ],
        ]);
    }

    public function withSources(): static
    {
        return $this->state(fn (): array => [
            'sources' => [
                ['url' => 'https://www.itto.int/', 'label' => 'International Tropical Timber Organization'],
                ['url' => 'https://www.minfof.cm/', 'label' => 'MINFOF — Ministry of Forestry and Wildlife'],
            ],
        ]);
    }
}

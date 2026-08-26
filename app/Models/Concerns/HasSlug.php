<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Auto-generates a unique slug on create from a source attribute (default
 * `name`). Uniqueness is enforced by appending an incrementing suffix before
 * insert (the DB unique index is the authoritative backstop). Reserved words
 * are avoided to prevent route collisions. Per spec §6, slugs are generated on
 * create; immutability-after-verified is enforced at the form layer.
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::creating(function ($model): void {
            if (blank($model->slug)) {
                $model->slug = static::generateUniqueSlug((string) $model->{$model->slugSourceColumn()});
            }
        });
    }

    protected function slugSourceColumn(): string
    {
        return 'name';
    }

    public static function generateUniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $fallback = Str::slug(class_basename(static::class));

        if ($base === '') {
            $base = $fallback;
        } elseif (in_array($base, static::reservedSlugs(), true)) {
            $base = $base.'-'.$fallback;
        }

        $query = method_exists(static::class, 'withTrashed') ? static::withTrashed() : static::query();

        $slug = $base;
        $suffix = 2;
        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @return list<string> */
    protected static function reservedSlugs(): array
    {
        return [
            'admin', 'dashboard', 'api', 'companies', 'company', 'species',
            'login', 'register', 'logout', 'pricing', 'verification', 'directory',
            'about', 'contact', 'request-quote', 'list-your-company', 'sitemap', 'robots',
            'marketplace', 'search', 'products', 'product',
        ];
    }
}

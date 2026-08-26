<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function articleStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the article resource pages for a content manager', function () {
    $article = Article::factory()->create();

    $this->actingAs(articleStaff('content_manager'));

    $this->get('/admin/articles')->assertOk();
    $this->get('/admin/articles/create')->assertOk();
    // Article's route key is its slug (set for public URLs), so Filament binds by slug.
    $this->get('/admin/articles/'.$article->slug.'/edit')->assertOk();
});

it('creates an article through the admin form', function () {
    $this->actingAs(articleStaff('admin'));

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Douala port congestion, quarter by quarter',
            'slug' => 'douala-port-congestion',
            'category' => ArticleCategory::Market->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->toDateTimeString(),
            'excerpt' => 'What the queue at Douala does to your lead times.',
            'body' => "## Waiting times\n\nBody copy.",
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::where('slug', 'douala-port-congestion')->firstOrFail();

    expect($article->category)->toBe(ArticleCategory::Market)
        ->and($article->status)->toBe(ArticleStatus::Published);

    // And it is immediately live on the public site.
    $this->get($article->url())->assertOk()->assertSee('Body copy.');
});

it('edits an article through the admin form', function () {
    $article = Article::factory()->draft()->create(['title' => 'Before']);

    $this->actingAs(articleStaff('admin'));

    Livewire::test(EditArticle::class, ['record' => $article->slug])
        ->fillForm(['title' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->refresh()->title)->toBe('After');
});

it('requires a publish date when the status is published', function () {
    $this->actingAs(articleStaff('admin'));

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Missing date',
            'category' => ArticleCategory::Guides->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['published_at']);
});

/**
 * Article::relatedSpecies() filters to published() at render time, so a linked
 * draft species renders as nothing. The option stays selectable — pre-publish
 * wiring is legitimate — but it must be labelled.
 */
it('marks unpublished species as drafts in the article cross-link select', function () {
    Species::factory()->create(['common_name' => 'Livepublishedspecies', 'is_published' => true]);
    Species::factory()->create(['common_name' => 'Draftonlyspecies', 'is_published' => false]);

    $this->actingAs(articleStaff('admin'));

    Livewire::test(CreateArticle::class)
        ->assertSee('Draftonlyspecies (draft)')
        ->assertDontSee('Livepublishedspecies (draft)');
});

/**
 * The regulation standard, enforced at the point of authoring — not just for
 * the markdown files in content/articles/ that EudrArticlePublishedTest covers.
 */
it('rejects a published regulation article with no disclaimer or dateless sources', function () {
    $this->actingAs(articleStaff('admin'));

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Some new regulation explained',
            'slug' => 'some-new-regulation-explained',
            'category' => ArticleCategory::Regulation->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->toDateTimeString(),
            'body' => "## What it says\n\nBody copy with no disclaimer.",
            'sources' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['body', 'sources']);

    // Disclaimer present but not first, and a source with no access date.
    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Some new regulation explained',
            'slug' => 'some-new-regulation-explained',
            'category' => ArticleCategory::Regulation->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->toDateTimeString(),
            'body' => "## What it says\n\n> This is not legal advice.",
            'sources' => [['url' => 'https://eur-lex.europa.eu/x', 'label' => 'EUR-Lex']],
        ])
        ->call('create')
        ->assertHasFormErrors(['body', 'sources']);

    expect(Article::where('slug', 'some-new-regulation-explained')->exists())->toBeFalse();
});

it('accepts a published regulation article that meets the standard', function () {
    $this->actingAs(articleStaff('admin'));

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Some new regulation explained',
            'slug' => 'compliant-regulation-piece',
            'category' => ArticleCategory::Regulation->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->toDateTimeString(),
            'body' => "> This explainer is not legal advice.\n\n## What it says\n\nBody copy.",
            'sources' => [['url' => 'https://eur-lex.europa.eu/x', 'label' => 'EUR-Lex (accessed 2026-08-26)']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Article::where('slug', 'compliant-regulation-piece')->exists())->toBeTrue();
});

it('leaves drafts and non-regulation articles free of the regulation standard', function () {
    $this->actingAs(articleStaff('admin'));

    // A regulation piece still being written.
    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Regulation work in progress',
            'slug' => 'regulation-wip',
            'category' => ArticleCategory::Regulation->value,
            'status' => ArticleStatus::Draft->value,
            'body' => 'Half-written.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // And a published market piece is never subject to it.
    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Market note',
            'slug' => 'market-note-no-disclaimer',
            'category' => ArticleCategory::Market->value,
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->toDateTimeString(),
            'body' => 'No disclaimer, no sources.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('deletes an article from the edit screen', function () {
    $article = Article::factory()->create();

    $this->actingAs(articleStaff('admin'));

    Livewire::test(EditArticle::class, ['record' => $article->slug])
        ->callAction('delete');

    expect(Article::whereKey($article->getKey())->exists())->toBeFalse();
});

it('lists articles in the admin table', function () {
    $article = Article::factory()->create(['title' => 'Listedpiece']);

    $this->actingAs(articleStaff('content_manager'));

    Livewire::test(ListArticles::class)
        ->assertCanSeeTableRecords([$article])
        ->assertSee('Listedpiece');
});

it('filters the admin table by status', function () {
    $published = Article::factory()->create();
    $draft = Article::factory()->draft()->create();

    $this->actingAs(articleStaff('content_manager'));

    Livewire::test(ListArticles::class)
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$published]);
});

it('hides the article resource from staff without the content permission', function () {
    $this->actingAs(articleStaff('verification_officer'));

    $this->get('/admin/articles')->assertForbidden();
});

it('blocks a non-staff user from the article resource entirely', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/articles')->assertForbidden();
});

it('blocks a guest from the article resource', function () {
    $this->get('/admin/articles')->assertRedirect('/admin/login');
});

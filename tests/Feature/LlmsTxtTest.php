<?php

use App\Models\Article;
use App\Models\Species;

it('serves llms.txt listing real published content', function () {
    Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-llms-test', 'is_published' => true]);
    Article::factory()->create(['title' => 'How to verify a Cameroon timber supplier', 'slug' => 'how-to-verify-a-supplier-llms-test']);

    $response = $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $body = $response->getContent();

    expect($body)
        ->toContain('Cameroon Timber Hub')
        ->toContain(route('species.show', 'iroko-llms-test'))
        ->toContain(route('insights.show', 'how-to-verify-a-supplier-llms-test'));
});

it('excludes unpublished content from llms.txt', function () {
    Species::factory()->create(['common_name' => 'Hidden', 'slug' => 'hidden-llms-test', 'is_published' => false]);

    $this->get('/llms.txt')->assertDontSee('hidden-llms-test');
});

it('links llms.txt from robots.txt', function () {
    $this->get('/robots.txt')->assertSee(url('/llms.txt'), false);
});

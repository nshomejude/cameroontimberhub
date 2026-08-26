<?php

use App\Support\ArticleBody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

// ArticleBody memoises rendered markdown in a static map that outlives any one
// test, while RefreshDatabase truncates between them. Two tests sharing
// byte-identical markdown whose `insights:` target resolves differently would
// otherwise silently reuse the first one's HTML.
uses()->beforeEach(fn () => ArticleBody::flushMemo())->in('Feature', 'Unit');

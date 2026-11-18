<?php

use App\Models\SecurityNewsArticle;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('lists classified news and lets the admin read the summary and original source', function () {
    actingAsAdmin();
    $visible = SecurityNewsArticle::factory()->create(['title' => 'SSHole', 'cve_ids' => ['CVE-2026-123158']]);
    $hidden = SecurityNewsArticle::factory()->create(['accepted_at' => null]);

    get(route('admin.security-news.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('security-news/index')
        ->has('articles.data', 1)
        ->where('articles.data.0.id', $visible->id)
        ->missing('articles.data.0.classification_key'));

    get(route('admin.security-news.show', $hidden))->assertNotFound();
    get(route('admin.security-news.show', $visible))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('security-news/show')
        ->where('article.summary', $visible->summary)
        ->where('article.url', $visible->url)
        ->where('article.cve_ids', ['CVE-2026-123158']));
});

it('restricts the news inbox and article details to admins', function () {
    $article = SecurityNewsArticle::factory()->create();
    get(route('admin.security-news.index'))->assertRedirect(route('admin.login'));

    actingAs(User::factory()->create());
    get(route('admin.security-news.index'))->assertForbidden();
    get(route('admin.security-news.show', $article))->assertForbidden();
});

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityNewsArticle;
use App\Services\Notices\JpcertNewsFeed;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

class SecurityNewsController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['listed_on'], '-listed_on');
        $articles = $table->search(SecurityNewsArticle::whereNotNull('accepted_at'), ['title', 'summary'])
            ->orderBy('listed_on', $table->direction())
            ->orderByDesc('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('security-news/index', [
            'articles' => JsonResource::collection($articles->through($this->readable(...))),
            'table' => $table->toArray(),
        ]);
    }

    public function show(SecurityNewsArticle $article): Response
    {
        abort_if($article->accepted_at === null, 404);

        return Inertia::render('security-news/show', ['article' => $this->readable($article)]);
    }

    /** @return array{id: int, title: string, section: string, published_on: string, url: string, summary: ?string, reason: ?string, matched_tags: list<string>, cve_ids: list<string>} */
    private function readable(SecurityNewsArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'section' => $article->section === JpcertNewsFeed::FLASH ? 'CyberNewsFlash' : __('Cảnh báo chính thức'),
            'published_on' => $article->published_on->format(__('d/m/Y')),
            'url' => $article->url,
            'summary' => $article->summary,
            'reason' => $article->reason,
            'matched_tags' => $article->matched_tags ?? [],
            'cve_ids' => $article->cve_ids ?? [],
        ];
    }
}

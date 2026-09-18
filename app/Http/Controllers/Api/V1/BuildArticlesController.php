<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BuildArticlesRequest;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;

class BuildArticlesController extends Controller
{
    public function __invoke(BuildArticlesRequest $request): JsonResponse
    {
        $articles = Article::query()
            ->published()
            ->locale($request->validated('locale', 'es'))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            // The frontend schemaVersion 1 accepts at most 200 articles.
            ->limit(200)
            ->get();

        return response()->json([
            'schemaVersion' => 1,
            'generatedAt' => now('UTC')->toIso8601ZuluString(),
            'articles' => ArticleResource::collection($articles)->resolve($request),
        ]);
    }
}

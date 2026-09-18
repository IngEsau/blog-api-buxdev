<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        // Content from the frontend's schemaVersion 1 development fixture.
        $articles = json_decode(
            file_get_contents(__DIR__.'/data/articles.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($articles as $article) {
            Article::firstOrCreate(
                ['article_group_id' => $article['article_group_id'], 'locale' => $article['locale']],
                [
                    'slug' => $article['slug'],
                    'title' => $article['title'],
                    'excerpt' => $article['excerpt'],
                    'author_name' => $article['author']['name'],
                    'content_json' => $article['content'],
                    'status' => 'published',
                    'published_at' => Carbon::parse($article['publishedAt'])->utc(),
                    'created_at' => Carbon::parse($article['publishedAt'])->utc(),
                    'updated_at' => Carbon::parse($article['updatedAt'])->utc(),
                ],
            );
        }
    }
}

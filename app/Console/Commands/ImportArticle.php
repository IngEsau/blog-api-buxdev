<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Support\ArticleDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportArticle extends Command
{
    protected $signature = 'blog:import {file : Local versioned JSON document} {--dry-run : Validate without writing}';

    protected $description = 'Validate and publish a V1 article idempotently';

    public function handle(): int
    {
        $path = realpath($this->argument('file'));
        if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            $this->error('Expected a readable local JSON file of at most 1 MiB.');

            return self::FAILURE;
        }

        try {
            $document = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($document)) {
                throw ValidationException::withMessages(['document' => 'Expected a JSON object.']);
            }
            $input = ArticleDocument::validate($document);
            $result = DB::transaction(function () use ($input): string {
                $article = Article::query()->where('article_group_id', $input['article_group_id'])
                    ->where('locale', $input['locale'])->lockForUpdate()->first();
                $slugOwner = Article::query()->where('locale', $input['locale'])->where('slug', $input['slug'])->first();
                if ($slugOwner && (! $article || $slugOwner->id !== $article->id)) {
                    throw ValidationException::withMessages(['document' => 'Slug belongs to another article.']);
                }
                // Changing a published URL requires an explicit redirect/migration, outside this importer.
                if ($article && $article->slug !== $input['slug']) {
                    throw ValidationException::withMessages(['document' => 'Existing article slugs cannot be changed.']);
                }
                $article ??= new Article;
                $article->fill([
                    'article_group_id' => $input['article_group_id'],
                    'locale' => $input['locale'],
                    'slug' => $input['slug'],
                    'title' => $input['title'],
                    'excerpt' => $input['excerpt'],
                    'author_name' => $input['author']['name'],
                    'content_json' => $input['content'],
                    'status' => 'published',
                    'published_at' => Carbon::parse($input['publishedAt'])->utc(),
                ]);
                $updated = Carbon::parse($input['updatedAt'])->utc();
                if ($article->exists) {
                    if ($updated->lt($article->updated_at) || ($article->isDirty() && $updated->equalTo($article->updated_at))) {
                        throw ValidationException::withMessages(['document' => 'Content changes require a newer updatedAt.']);
                    }
                    if ($article->isDirty('published_at')) {
                        throw ValidationException::withMessages(['document' => 'Original publication date must be preserved.']);
                    }
                }
                $article->updated_at = $updated;
                if (! $article->exists) {
                    $article->created_at = Carbon::parse($input['publishedAt'])->utc();
                }
                if (! $article->isDirty()) {
                    return 'Unchanged';
                }
                if ($this->option('dry-run')) {
                    return 'Valid (dry run)';
                }
                $article->save();

                return 'Published';
            });
            $this->info($result.': '.$input['locale'].'/'.$input['slug']);

            return self::SUCCESS;
        } catch (\JsonException|ValidationException) {
            $this->error('Invalid article document or conflicting revision. No article was written.');

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Article import failed. Check database configuration. No article was written.');

            return self::FAILURE;
        }
    }
}

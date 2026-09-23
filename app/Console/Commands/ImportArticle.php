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
            $document = json_decode(
                file_get_contents($path),
                true,
                64,
                JSON_THROW_ON_ERROR
            );

            if (! is_array($document)) {
                throw ValidationException::withMessages([
                    'document' => 'Expected a JSON object.',
                ]);
            }

            $input = ArticleDocument::validate($document);

            $result = DB::transaction(function () use ($input): string {
                $article = Article::query()
                    ->where('article_group_id', $input['article_group_id'])
                    ->where('locale', $input['locale'])
                    ->lockForUpdate()
                    ->first();

                $slugOwner = Article::query()
                    ->where('locale', $input['locale'])
                    ->where('slug', $input['slug'])
                    ->first();

                if ($slugOwner && (! $article || $slugOwner->id !== $article->id)) {
                    throw ValidationException::withMessages([
                        'document' => 'Slug belongs to another article.',
                    ]);
                }

                if ($article && $article->slug !== $input['slug']) {
                    throw ValidationException::withMessages([
                        'document' => 'Existing article slugs cannot be changed.',
                    ]);
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
                    if (
                        $article->isDirty('content_json')
                        && $this->jsonEquivalent(
                            $this->decodeOriginalContent($article),
                            $input['content']
                        )
                    ) {
                        $article->syncOriginalAttribute('content_json');
                    }

                    if (
                        $updated->lt($article->updated_at)
                        || (
                            $article->isDirty()
                            && $updated->equalTo($article->updated_at)
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'document' => 'Content changes require a newer updatedAt.',
                        ]);
                    }

                    if ($article->isDirty('published_at')) {
                        throw ValidationException::withMessages([
                            'document' => 'Original publication date must be preserved.',
                        ]);
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

                return $article->wasRecentlyCreated
                    ? 'Created'
                    : 'Updated';
            });
        } catch (\JsonException|ValidationException) {
            $this->error('Invalid article document or conflicting revision. No article was written.');

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Article import failed. Check database configuration. No article was written.');

            return self::FAILURE;
        }

        $this->info($result.': '.$input['locale'].'/'.$input['slug']);

        return self::SUCCESS;
    }

    private function decodeOriginalContent(Article $article): mixed
    {
        $original = $article->getRawOriginal('content_json');

        if (! is_string($original)) {
            return $original;
        }

        return json_decode(
            $original,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    }

    private function jsonEquivalent(mixed $left, mixed $right): bool
    {
        return $this->canonicalizeJson($left)
            === $this->canonicalizeJson($right);
    }

    private function canonicalizeJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalizeJson($item),
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeJson($item);
        }

        return $value;
    }
}

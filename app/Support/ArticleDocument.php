<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Versioned editorial input mapped to the existing Article model and V1 resource. */
final class ArticleDocument
{
    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public static function validate(array $document): array
    {
        Validator::make(['document' => $document], [
            'document' => 'required|array:schemaVersion,article',
            'document.schemaVersion' => ['required', function ($attribute, $value, $fail) {
                if ($value !== 1) {
                    $fail('Unsupported schema version.');
                }
            }],
            'document.article' => 'required|array:article_group_id,locale,slug,title,excerpt,author,content,publishedAt,updatedAt',
            'document.article.article_group_id' => 'required|uuid',
            'document.article.locale' => 'required|in:es,en',
            'document.article.slug' => ['required', 'string', 'max:100', 'regex:'.self::SLUG],
            'document.article.title' => 'required|string|max:160',
            'document.article.excerpt' => 'required|string|max:500',
            'document.article.author' => 'required|array:name',
            'document.article.author.name' => 'required|string|max:100',
            'document.article.content' => 'required|array|min:1|max:250',
            'document.article.publishedAt' => 'required|string|max:40',
            'document.article.updatedAt' => 'required|string|max:40',
        ])->validate();

        $article = $document['article'];
        self::text($article['title'], 160);
        self::text($article['excerpt'], 500);
        self::text($article['author']['name'], 100);
        $published = self::date($article['publishedAt']);
        $updated = self::date($article['updatedAt']);
        self::require($updated >= $published, 'Update precedes publication.');
        self::require($updated <= now(), 'Publication and update dates must not be in the future.');
        self::require(array_is_list($article['content']), 'Content must be a list.');

        $ids = [];
        foreach ($article['content'] as $block) {
            self::require(is_array($block), 'Invalid content block.');
            switch ($block['type'] ?? null) {
                case 'heading':
                    self::keys($block, ['type', 'level', 'id', 'text']);
                    self::require(in_array($block['level'], [2, 3], true), 'Invalid heading level.');
                    self::text($block['id'], 100);
                    self::require((bool) preg_match(self::SLUG, $block['id']), 'Invalid heading ID.');
                    self::require(! isset($ids[$block['id']]), 'Duplicate heading ID.');
                    $ids[$block['id']] = true;
                    self::text($block['text'], 240);
                    break;
                case 'paragraph':
                case 'quote':
                    self::keys($block, ['type', 'content']);
                    self::inline($block['content']);
                    break;
                case 'list':
                    self::keys($block, ['type', 'ordered', 'items']);
                    self::require(is_bool($block['ordered']), 'Invalid list ordering.');
                    self::collection($block['items'], 50);
                    foreach ($block['items'] as $item) {
                        self::inline($item);
                    }
                    break;
                case 'code':
                    self::keys($block, ['type', 'code'], ['language']);
                    self::text($block['code'], 50000);
                    if (array_key_exists('language', $block)) {
                        self::text($block['language'], 32);
                        self::require((bool) preg_match('/^[a-z0-9][a-z0-9+#.-]*$/iD', $block['language']), 'Invalid code language.');
                    }
                    break;
                default:
                    self::require(false, 'Unsupported content block.');
            }
        }

        return $article;
    }

    private static function inline(mixed $items): void
    {
        self::collection($items, 64);
        foreach ($items as $item) {
            self::require(is_array($item), 'Invalid inline content.');
            if (($item['type'] ?? null) === 'text') {
                self::keys($item, ['type', 'value']);
                self::text($item['value'], 5000);
            } elseif (($item['type'] ?? null) === 'link') {
                self::keys($item, ['type', 'text', 'href']);
                self::text($item['text'], 5000);
                self::text($item['href'], 2048);
                $href = $item['href'];
                self::require(! preg_match('/[\x00-\x20\x7f\\\\]/', $href), 'Unsafe link.');
                if (str_starts_with($href, '/')) {
                    self::require(! str_starts_with($href, '//'), 'Unsafe internal link.');
                } else {
                    $url = parse_url($href);
                    self::require($url !== false && strtolower($url['scheme'] ?? '') === 'https'
                        && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
                        && filter_var($href, FILTER_VALIDATE_URL) !== false, 'Links must use HTTPS.');
                }
            } else {
                self::require(false, 'Unsupported inline content.');
            }
        }
    }

    private static function date(string $value): DateTimeImmutable
    {
        self::require((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value), 'Invalid ISO date.');
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw ValidationException::withMessages(['document' => 'Invalid ISO date.']);
        }
        $errors = DateTimeImmutable::getLastErrors();
        self::require($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0), 'Invalid ISO date.');

        return $date;
    }

    private static function keys(array $value, array $required, array $optional = []): void
    {
        self::require(! array_diff($required, array_keys($value))
            && ! array_diff(array_keys($value), [...$required, ...$optional]), 'Unexpected or missing fields.');
    }

    private static function text(mixed $value, int $max): void
    {
        self::require(is_string($value) && trim($value) !== ''
            && strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2 <= $max, 'Invalid or oversized text.');
    }

    private static function collection(mixed $value, int $max): void
    {
        self::require(is_array($value) && array_is_list($value) && count($value) >= 1 && count($value) <= $max, 'Invalid collection.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }
}

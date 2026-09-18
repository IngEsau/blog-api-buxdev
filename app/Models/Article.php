<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = [
        'article_group_id',
        'locale',
        'slug',
        'title',
        'excerpt',
        'author_name',
        'content_json',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', 'published')->whereNotNull('published_at');
    }

    #[Scope]
    protected function locale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }
}

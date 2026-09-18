<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'locale' => $this->locale,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'author' => ['name' => $this->author_name],
            'content' => $this->content_json,
            'publishedAt' => $this->published_at->utc()->toIso8601ZuluString(),
            'updatedAt' => $this->updated_at->utc()->toIso8601ZuluString(),
        ];
    }
}

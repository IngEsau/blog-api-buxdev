<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 25));
    }

    public function test_real_article_import_is_idempotent_and_exposes_v1_content(): void
    {
        $path = database_path('articles/wordpress-seo-spam.es.json');
        $this->artisan('blog:import', ['file' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('articles', 0);
        $this->artisan('blog:import', ['file' => $path])->assertSuccessful();
        $before = Article::first()->getAttributes();
        $this->artisan('blog:import', ['file' => $path])->expectsOutputToContain('Unchanged')->assertSuccessful();
        $this->assertDatabaseCount('articles', 1);
        $this->assertSame($before, Article::first()->getAttributes());
        $input = $this->document()['article'];
        $this->get('/v1/build/articles?locale=es')->assertOk()
            ->assertJsonPath('schemaVersion', 1)
            ->assertJsonPath('articles.0.slug', $input['slug'])
            ->assertJsonPath('articles.0.content', $input['content'])
            ->assertJsonPath('articles.0.publishedAt', $input['publishedAt'])
            ->assertJsonPath('articles.0.updatedAt', $input['updatedAt']);
    }

    public function test_newer_revision_updates_existing_row_but_stale_or_conflicting_revisions_fail(): void
    {
        $document = $this->document();
        $this->runImport($document, 0);
        $id = Article::first()->id;
        $revision = $document;
        $revision['article']['title'] = 'Revisión editorial';
        $this->runImport($revision, 1);
        $revision['article']['updatedAt'] = '2026-09-24T00:00:00Z';
        $this->runImport($revision, 0);
        $this->assertDatabaseCount('articles', 1);
        $this->assertSame($id, Article::first()->id);
        $this->runImport($document, 1);
        $collision = $revision;
        $collision['article']['article_group_id'] = '4bc9a338-08cd-4556-bd71-2feac36d4d97';
        $this->runImport($collision, 1);
        $this->assertSame('Revisión editorial', Article::first()->title);
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_documents_are_rejected_without_writes(string $mutation): void
    {
        $document = $this->document();
        switch ($mutation) {
            case 'version': $document['schemaVersion'] = '1';
                break;
            case 'unknown': $document['article']['html'] = '<script>alert(1)</script>';
                break;
            case 'slug': $document['article']['slug'] = '../invalid';
                break;
            case 'date': $document['article']['publishedAt'] = '2026-02-30T00:00:00Z';
                break;
            case 'future': $document['article']['updatedAt'] = '2099-01-01T00:00:00Z';
                break;
            case 'order': $document['article']['updatedAt'] = '2020-01-01T00:00:00Z';
                break;
            case 'locale': $document['article']['locale'] = 'fr';
                break;
            case 'heading': $document['article']['content'][1]['level'] = 1;
                break;
            case 'duplicate': $document['article']['content'][] = $document['article']['content'][1];
                break;
            case 'oversize': $document['article']['title'] = str_repeat('a', 161);
                break;
            case 'html': $document['article']['content'] = [['type' => 'html', 'value' => '<b>bad</b>']];
                break;
            default: $document['article']['content'][0]['content'][1]['href'] = $mutation;
        }
        $this->runImport($document, 1);
        $this->assertDatabaseCount('articles', 0);
    }

    public static function invalidDocuments(): array
    {
        return array_map(fn ($value) => [$value], [
            'version', 'unknown', 'slug', 'date', 'future', 'order', 'locale', 'heading',
            'duplicate', 'oversize', 'html', 'javascript:alert(1)', 'data:text/html,bad',
            'http://example.com', '//example.com', '/\\example.com', 'https://user:pass@example.com',
        ]);
    }

    private function document(): array
    {
        return json_decode(file_get_contents(database_path('articles/wordpress-seo-spam.es.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    private function runImport(array $document, int $exit): void
    {
        $path = tempnam(sys_get_temp_dir(), 'article-test-');
        try {
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
            $this->artisan('blog:import', ['file' => $path])->assertExitCode($exit);
        } finally {
            unlink($path);
        }
    }
}

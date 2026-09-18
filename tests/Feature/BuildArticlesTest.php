<?php

namespace Tests\Feature;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuildArticlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-18T16:00:00Z'));
    }

    public function test_spanish_feed_matches_the_exact_v1_contract(): void
    {
        $article = $this->createArticle();

        $this->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('schemaVersion', 1)
            ->assertExactJson([
                'schemaVersion' => 1,
                'generatedAt' => '2026-09-18T16:00:00Z',
                'articles' => [[
                    'id' => (string) $article->id,
                    'locale' => 'es',
                    'slug' => $article->slug,
                    'title' => $article->title,
                    'excerpt' => $article->excerpt,
                    'author' => ['name' => 'BUXDEV'],
                    'content' => $article->content_json,
                    'publishedAt' => '2026-09-10T14:00:00Z',
                    'updatedAt' => '2026-09-18T16:00:00Z',
                ]],
            ]);
    }

    public function test_drafts_never_appear_even_with_a_publication_date(): void
    {
        $published = $this->createArticle();
        $this->createArticle(['status' => 'draft']);
        $this->createArticle(['status' => 'draft', 'published_at' => null]);

        $this->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertJsonCount(1, 'articles')
            ->assertJsonPath('articles.0.id', (string) $published->id);
    }

    public function test_published_records_without_a_publication_date_are_excluded(): void
    {
        $this->createArticle(['published_at' => null]);

        $this->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertJsonPath('articles', []);
    }

    public function test_feed_filters_each_locale(): void
    {
        $spanish = $this->createArticle();
        $english = $this->createArticle(['locale' => 'en']);

        foreach (['es' => $spanish, 'en' => $english] as $locale => $article) {
            $this->get('/v1/build/articles?locale='.$locale)
                ->assertOk()
                ->assertJsonCount(1, 'articles')
                ->assertJsonPath('articles.0.locale', $locale)
                ->assertJsonPath('articles.0.id', (string) $article->id);
        }
    }

    public function test_feed_is_ordered_by_publication_date_descending(): void
    {
        $middle = $this->createArticle(['published_at' => '2026-09-12 12:00:00']);
        $oldest = $this->createArticle(['published_at' => '2026-09-10 12:00:00']);
        $newest = $this->createArticle(['published_at' => '2026-09-17 12:00:00']);

        $response = $this->get('/v1/build/articles?locale=es')->assertOk();

        $this->assertSame(
            [(string) $newest->id, (string) $middle->id, (string) $oldest->id],
            array_column($response->json('articles'), 'id'),
        );
    }

    public function test_missing_locale_defaults_to_spanish(): void
    {
        $this->createArticle();
        $this->createArticle(['locale' => 'en']);

        $this->get('/v1/build/articles')
            ->assertOk()
            ->assertJsonCount(1, 'articles')
            ->assertJsonPath('articles.0.locale', 'es');
    }

    #[DataProvider('invalidLocaleQueries')]
    public function test_invalid_locale_returns_json_bad_request(string $query): void
    {
        $this->get('/v1/build/articles?'.$query)
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('locale')
            ->assertJsonMissingPath('trace');
    }

    public static function invalidLocaleQueries(): array
    {
        return [
            'unsupported' => ['locale=fr'],
            'uppercase' => ['locale=ES'],
            'empty' => ['locale='],
            'array' => ['locale[]=es'],
        ];
    }

    public function test_post_is_not_allowed_and_returns_json(): void
    {
        $this->post('/v1/build/articles?locale=es')
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonStructure(['message']);
    }

    public function test_the_api_prefixed_endpoint_does_not_exist(): void
    {
        $this->get('/api/v1/build/articles?locale=es')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_feed_and_preflight_do_not_enable_cors(): void
    {
        $this->withHeader('Origin', 'https://buxdev.com')
            ->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->withHeaders([
            'Origin' => 'https://buxdev.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/v1/build/articles?locale=es')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_empty_feed_is_a_v1_envelope_with_an_empty_array(): void
    {
        $this->get('/v1/build/articles?locale=en')
            ->assertOk()
            ->assertExactJson([
                'schemaVersion' => 1,
                'generatedAt' => '2026-09-18T16:00:00Z',
                'articles' => [],
            ]);
    }

    public function test_feed_respects_the_v1_limit_of_200_articles(): void
    {
        for ($index = 0; $index < 201; $index++) {
            $this->createArticle(['published_at' => now()->subMinutes($index)]);
        }

        $this->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertJsonCount(200, 'articles')
            ->assertJsonPath('articles.0.publishedAt', '2026-09-18T16:00:00Z')
            ->assertJsonPath('articles.199.publishedAt', '2026-09-18T12:41:00Z');
    }

    public function test_seeder_is_repeatable_and_preserves_structured_frontend_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('articles', 2);
        $response = $this->get('/v1/build/articles?locale=es')
            ->assertOk()
            ->assertJsonCount(2, 'articles');

        $fixtures = json_decode(
            file_get_contents(database_path('seeders/data/articles.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($response->json('articles') as $article) {
            $fixture = collect($fixtures)->firstWhere('slug', $article['slug']);
            $this->assertJsonStringEqualsJsonString(
                json_encode($fixture['content'], JSON_THROW_ON_ERROR),
                json_encode($article['content'], JSON_THROW_ON_ERROR),
            );
            $this->assertSame($fixture['author'], $article['author']);
            $this->assertSame('es', $article['locale']);
            $this->assertGreaterThanOrEqual(
                strtotime($article['publishedAt']),
                strtotime($article['updatedAt']),
            );
            $this->assertStringNotContainsString('<', json_encode($article['content']));
        }
    }

    public function test_model_casts_content_and_dates(): void
    {
        $article = $this->createArticle()->fresh();

        $this->assertIsArray($article->content_json);
        $this->assertInstanceOf(CarbonImmutable::class, $article->published_at);
        $this->assertInstanceOf(CarbonImmutable::class, $article->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $article->updated_at);
    }

    public function test_same_slug_and_group_are_allowed_for_different_locales(): void
    {
        $spanish = $this->createArticle();
        $this->createArticle([
            'locale' => 'en',
            'slug' => $spanish->slug,
            'article_group_id' => $spanish->article_group_id,
        ]);

        $this->assertDatabaseCount('articles', 2);
    }

    #[DataProvider('invalidDatabaseRecords')]
    public function test_database_enforces_article_constraints(array $overrides): void
    {
        $this->createArticle([
            'slug' => 'same-slug',
            'article_group_id' => 'd9d1bb29-d8c7-46e9-8a63-d4b979578d5c',
        ]);

        $this->expectException(QueryException::class);

        $this->createArticle($overrides);
    }

    public static function invalidDatabaseRecords(): array
    {
        return [
            'invalid locale' => [['locale' => 'fr']],
            'invalid status' => [['status' => 'archived']],
            'duplicate localized slug' => [['slug' => 'same-slug']],
            'duplicate localized group' => [['article_group_id' => 'd9d1bb29-d8c7-46e9-8a63-d4b979578d5c']],
        ];
    }

    private function createArticle(array $overrides = []): Article
    {
        return Article::create(array_replace([
            'article_group_id' => (string) Str::uuid(),
            'locale' => 'es',
            'slug' => 'article-'.Str::uuid(),
            'title' => 'Contenido estructurado',
            'excerpt' => 'Un artículo de prueba con contenido estructurado.',
            'author_name' => 'BUXDEV',
            'content_json' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'value' => 'Contenido del artículo.']],
            ]],
            'status' => 'published',
            'published_at' => '2026-09-10 14:00:00',
        ], $overrides));
    }
}

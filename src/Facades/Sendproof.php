<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Sendproof\Laravel\SendproofManager;

/**
 * Entry point for recording effects and customising identity and authorization.
 *
 * @method static bool tryRecordEffect(string $kind, string $description, mixed $data = null)
 * @method static bool tryRecordEmail(string $description, string $from, iterable $to, iterable $cc, string $subject, string $text, ?string $html = null)
 * @method static bool tryRecordHttp(string $description, string $method, string $url, ?int $status = null, mixed $body = null)
 * @method static bool tryRecordJob(string $description, string $name, mixed $arguments, ?string $queue = null, ?\DateTimeInterface $runAt = null)
 * @method static bool tryRecordMessage(string $description, string $topic, mixed $payload, ?string $key = null)
 * @method static bool isPreview()
 * @method static void addJourneys(\Sendproof\Core\Journeys\JourneyDocument $definition, array|\stdClass $openApi)
 * @method static void identifyUsing(Closure $resolver)
 * @method static void authorizeUsing(Closure $authorizer)
 * @method static \Aws\DynamoDb\DynamoDbClient hookDynamoDb(\Aws\DynamoDb\DynamoDbClient $client, ?string $name = null)
 * @method static \Elastic\Elasticsearch\Client hookElasticsearch(\Elastic\Elasticsearch\Client $client, ?string $name = null)
 * @method static \OpenSearch\Client hookOpenSearch(\OpenSearch\Client $client, ?string $name = null)
 * @method static \Google\Cloud\Firestore\FirestoreClient hookFirestore(\Google\Cloud\Firestore\FirestoreClient $client, ?string $name = null)
 *
 * @see SendproofManager
 */
final class Sendproof extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SendproofManager::class;
    }
}

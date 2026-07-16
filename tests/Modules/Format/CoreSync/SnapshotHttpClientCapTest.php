<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Bounded-чтение ответов ядра (D-SAT-FETCH-JSON-CAP): fetchWithRetries читает тело потоком чанками с
 * бегущим счётчиком и обрывает ДО накопления при превышении кэпа (паттерн ArtifactDownloader).
 * Транспорт вынесен в тестируемый шов openStream() (реальный HTTP — SAT-RT round-trip), фейк отдаёт
 * заранее заданный поток + заголовки и считает вызовы. Проверяем: укладывающийся ответ = как раньше;
 * превышение = провал попытки (null) без раздутия; статус-код извлекается; 4xx fast-fail без ретрая.
 */
class SnapshotHttpClientCapTest extends TestCase
{
    /**
     * Клиент с подменённым openStream: отдаёт $body в php://temp + $headers, считает вызовы и байты.
     */
    private function clientReturning(string $body, array $headers)
    {
        return new class($body, $headers) extends SnapshotHttpClient {
            /** @var string */
            private $body;
            /** @var array<int,string> */
            private $headers;
            /** @var int */
            public $opens = 0;

            public function __construct(string $body, array $headers)
            {
                parent::__construct(null);
                $this->body = $body;
                $this->headers = $headers;
            }

            protected function openStream(string $url): array
            {
                $this->opens++;
                $fh = fopen('php://temp', 'r+b');
                fwrite($fh, $this->body);
                rewind($fh);

                return ['stream' => $fh, 'headers' => $this->headers];
            }
        };
    }

    private function fetch(SnapshotHttpClient $client, int $maxBytes)
    {
        $ref = new ReflectionClass(SnapshotHttpClient::class);
        $m = $ref->getMethod('fetchWithRetries');
        $m->setAccessible(true);

        return $m->invokeArgs($client, ['https://core.example/x', 'secret-token', $maxBytes]);
    }

    public function testResponseUnderCapReturnedIntact(): void
    {
        $body = str_repeat('a', 500);
        $client = $this->clientReturning($body, ['HTTP/1.1 200 OK']);

        $this->assertSame($body, $this->fetch($client, 1024), 'ответ под кэпом возвращается целиком');
        $this->assertSame(1, $client->opens, 'успех с первой попытки');
    }

    public function testResponseExactlyAtCapReturnedIntact(): void
    {
        $body = str_repeat('b', 1024);
        $client = $this->clientReturning($body, ['HTTP/1.1 200 OK']);

        $this->assertSame($body, $this->fetch($client, 1024), 'ровно кэп — не превышение, тело целое');
    }

    public function testResponseOverCapFailsAttemptWithoutBuffering(): void
    {
        // KILL-проба кэпа: тело больше кэпа → попытка проваливается (null), тело НЕ возвращается.
        // Без проверки счётчика (`$read > $maxBytes`) readCapped вернул бы всю строку → тест красный.
        $body = str_repeat('c', 5000);
        $client = $this->clientReturning($body, ['HTTP/1.1 200 OK']);

        $this->assertNull($this->fetch($client, 1024), 'превышение кэпа → провал попытки без раздутия');
    }

    public function testStatusExtractedAndFourxxFastFailsWithoutRetry(): void
    {
        $client = $this->clientReturning('not found', ['HTTP/1.1 404 Not Found']);

        $this->assertNull($this->fetch($client, 1024), '4xx → null');
        $this->assertSame(1, $client->opens, '4xx не ретраится (fast-fail)');
    }

    public function testNetworkFailureExhaustsRetries(): void
    {
        $client = new class extends SnapshotHttpClient {
            /** @var int */
            public $opens = 0;

            public function __construct()
            {
                parent::__construct(null);
            }

            protected function openStream(string $url): array
            {
                $this->opens++;

                return ['stream' => false, 'headers' => []];
            }
        };

        $result = $this->fetch($client, 1024);
        $this->assertNull($result, 'исчерпание попыток → null');
        $this->assertSame(SnapshotHttpClient::MAX_ATTEMPTS, $client->opens, 'все попытки использованы');
    }

    public function testTokenNeverAppearsInOversizeWarning(): void
    {
        $logged = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $m) use (&$logged): void {
            $logged[] = $m;
        });

        $client = new class('c', $logger) extends SnapshotHttpClient {
            /** @var string */
            private $body;

            public function __construct(string $seed, $logger)
            {
                parent::__construct($logger);
                $this->body = str_repeat($seed, 5000);
            }

            protected function openStream(string $url): array
            {
                $fh = fopen('php://temp', 'r+b');
                fwrite($fh, $this->body);
                rewind($fh);

                return ['stream' => $fh, 'headers' => ['HTTP/1.1 200 OK']];
            }
        };

        $this->fetch($client, 1024);

        $this->assertNotEmpty($logged, 'превышение кэпа логируется');
        foreach ($logged as $m) {
            $this->assertStringNotContainsString('secret-token', $m, 'токен не в лог-сообщении о превышении');
        }
    }
}

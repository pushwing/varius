<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PhotoPickerService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class PhotoPickerServiceTest extends CIUnitTestCase
{
    /**
     * 지정한 상태코드·JSON 바디를 돌려주는 ResponseInterface 목을 만든다.
     *
     * @param array<string, mixed> $body
     */
    private function makeResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn((string) json_encode($body));

        return $response;
    }

    /**
     * sleep 을 no-op 으로 대체하고, 호출된 대기 초를 기록하는 sleeper 를 주입한 서비스.
     *
     * @param list<int> $sleepCalls 대기 초가 이 배열에 순서대로 쌓인다.
     */
    private function makeService(CURLRequest $client, array &$sleepCalls, int $maxItems = 10): PhotoPickerService
    {
        $sleeper = static function (int $seconds) use (&$sleepCalls): void {
            $sleepCalls[] = $seconds;
        };

        return new PhotoPickerService($client, $maxItems, $sleeper);
    }

    public function testCreateSessionReturnsSessionDataWithPickerUri(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://photospicker.googleapis.com/v1/sessions',
                $this->callback(static function (array $options): bool {
                    // Authorization Bearer 헤더가 실려야 한다.
                    return ($options['headers']['Authorization'] ?? null) === 'Bearer ya29.token';
                }),
            )
            ->willReturn($this->makeResponse(200, [
                'id' => 'sess-123',
                'pickerUri' => 'https://photos.google.com/picker/sess-123',
                'pollingConfig' => ['pollInterval' => '2s'],
            ]));

        $noSleep = [];
        $session = $this->makeService($client, $noSleep)->createSession('ya29.token');

        $this->assertSame('sess-123', $session['id']);
        $this->assertSame('https://photos.google.com/picker/sess-123', $session['pickerUri']);
    }

    public function testCreateSessionThrowsOnErrorStatus(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('request')->willReturn($this->makeResponse(401, ['error' => ['message' => 'unauthorized']]));

        $noSleep = [];
        $service = $this->makeService($client, $noSleep);

        $this->expectException(RuntimeException::class);
        $service->createSession('bad-token');
    }

    public function testGetSessionReturnsCurrentState(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://photospicker.googleapis.com/v1/sessions/sess-123', $this->anything())
            ->willReturn($this->makeResponse(200, [
                'id' => 'sess-123',
                'mediaItemsSet' => false,
            ]));

        $noSleep = [];
        $session = $this->makeService($client, $noSleep)->getSession('ya29.token', 'sess-123');

        $this->assertSame('sess-123', $session['id']);
        $this->assertFalse($session['mediaItemsSet']);
    }

    public function testPollUntilReadyRetriesUntilMediaItemsSetUsingPollInterval(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('request')->willReturnOnConsecutiveCalls(
            $this->makeResponse(200, ['id' => 'sess-1', 'mediaItemsSet' => false, 'pollingConfig' => ['pollInterval' => '3s']]),
            $this->makeResponse(200, ['id' => 'sess-1', 'mediaItemsSet' => false, 'pollingConfig' => ['pollInterval' => '3s']]),
            $this->makeResponse(200, ['id' => 'sess-1', 'mediaItemsSet' => true, 'pollingConfig' => ['pollInterval' => '3s']]),
        );

        $sleepCalls = [];
        $session = $this->makeService($client, $sleepCalls)->pollUntilReady('ya29.token', 'sess-1');

        $this->assertTrue($session['mediaItemsSet']);
        // 준비되지 않은 두 번의 응답 뒤에만 대기하므로 sleep 은 2회, 각 3초.
        $this->assertSame([3, 3], $sleepCalls);
    }

    public function testPollUntilReadyThrowsWhenAttemptsExhausted(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('request')->willReturn(
            $this->makeResponse(200, ['id' => 'sess-1', 'mediaItemsSet' => false, 'pollingConfig' => ['pollInterval' => '1s']]),
        );

        $sleepCalls = [];
        $service = new PhotoPickerService($client, 10, static function (int $s) use (&$sleepCalls): void {
            $sleepCalls[] = $s;
        }, 3); // maxPollAttempts = 3

        $this->expectException(RuntimeException::class);
        $service->pollUntilReady('ya29.token', 'sess-1');
    }

    public function testListPickedMediaItemsCapsAtMaxItems(): void
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = ['id' => 'item-' . $i, 'baseUrl' => 'https://base/' . $i];
        }

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('https://photospicker.googleapis.com/v1/mediaItems'),
                $this->anything(),
            )
            ->willReturn($this->makeResponse(200, ['mediaItems' => $items]));

        $noSleep = [];
        $result = $this->makeService($client, $noSleep)->listPickedMediaItems('ya29.token', 'sess-1');

        $this->assertCount(10, $result);
        $this->assertSame('item-1', $result[0]['id']);
        $this->assertSame('item-10', $result[9]['id']);
    }

    public function testListPickedMediaItemsReturnsEmptyWhenNoSelection(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('request')->willReturn($this->makeResponse(200, []));

        $noSleep = [];
        $result = $this->makeService($client, $noSleep)->listPickedMediaItems('ya29.token', 'sess-1');

        $this->assertSame([], $result);
    }
}

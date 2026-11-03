<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Http;

use Decionis\Client\Http\CurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

/** A loopback server that answers slowly: the client must give up inside its budget. */
#[RequiresPhpExtension('curl')]
final class CurlClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $router = sys_get_temp_dir() . '/decionis-client-router-' . getmypid() . '.php';
        file_put_contents($router, '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/slow")) { usleep(800000); } header("Content-Type: application/json"); echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "key" => $_SERVER["HTTP_IDEMPOTENCY_KEY"] ?? null]);');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        self::$server = $process;
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', self::$port) === false; $i++) {
            usleep(20000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private static function client(int $totalMs): CurlClient
    {
        $factory = new Psr17Factory();
        return new CurlClient($factory, $factory, min(100, $totalMs), $totalMs);
    }

    public function testAnswersWithinTheBudget(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'http://127.0.0.1:' . self::$port . '/fast')
            ->withHeader('Idempotency-Key', 'k-9')
            ->withBody($factory->createStream('{}'));
        $response = self::client(2000)->sendRequest($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['method' => 'POST', 'key' => 'k-9'], json_decode((string) $response->getBody(), true));
    }

    public function testGivesUpAtTheTotalTimeout(): void
    {
        $factory = new Psr17Factory();
        $started = hrtime(true);
        try {
            self::client(120)->sendRequest($factory->createRequest('GET', 'http://127.0.0.1:' . self::$port . '/slow'));
            self::fail('expected a network error');
        } catch (NetworkExceptionInterface) {
            $elapsedMs = (hrtime(true) - $started) / 1e6;
            self::assertLessThan(400, $elapsedMs, 'a 120 ms budget must not wait for an 800 ms server');
        }
    }
}

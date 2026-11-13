<?php

namespace Tests\Apoio;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Cache PSR-16 num array, que também anota o TTL de cada gravação. Com $quebrado, toda
 * operação falha, como um Redis fora do ar.
 */
final class CacheEmMemoria implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $valores = [];

    /** @var array<string, int|DateInterval|null> */
    public array $ttls = [];

    public function __construct(private readonly bool $quebrado = false) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->falharSeQuebrado();

        return $this->valores[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->falharSeQuebrado();
        $this->valores[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->falharSeQuebrado();
        unset($this->valores[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->valores = [];

        return true;
    }

    /**
     * @param  iterable<string>  $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $valores = [];
        foreach ($keys as $key) {
            $valores[$key] = $this->get($key, $default);
        }

        return $valores;
    }

    /**
     * @param  iterable<string, mixed>  $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param  iterable<string>  $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->falharSeQuebrado();

        return isset($this->valores[$key]);
    }

    private function falharSeQuebrado(): void
    {
        if ($this->quebrado) {
            throw new RuntimeException('Conexão com o cache recusada.');
        }
    }
}

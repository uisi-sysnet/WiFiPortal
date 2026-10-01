<?php

namespace Tests\Support;

use RouterOS\Query;

/**
 * In-memory stand-in for a MikroTik reached through the RouterOS API.
 * Menus hold rows; print (with ?where filters), add (with place-before),
 * set, remove and move behave like RouterOS, so tests can check the final
 * configuration rather than the exact command order. Every command is kept
 * in $commands.
 */
class FakeRouterOs
{
    /** @var array<string, array<int, array<string, string>>> menu => rows */
    public array $menus = [];

    /** @var array<int, array{0:string,1:array<string,string>}> endpoint, attributes */
    public array $commands = [];

    private int $nextId = 1;

    private array $response = [];

    public function __construct(array $menus = [])
    {
        foreach ($menus as $menu => $rows) {
            foreach ($rows as $row) {
                $this->menus[$menu][] = ['.id' => '*'.dechex($this->nextId++)] + $row;
            }
        }
    }

    public function query(Query $query): static
    {
        $endpoint = (string) $query->getEndpoint();
        $set = [];
        $where = [];
        foreach ($query->getAttributes() as $word) {
            if (preg_match('/^=([^=]+)=(.*)$/s', $word, $m)) {
                $set[$m[1]] = $m[2];
            } elseif (preg_match('/^\?([^=]+)=(.*)$/s', $word, $m)) {
                $where[$m[1]] = $m[2];
            }
        }
        $this->commands[] = [$endpoint, $set];

        $menu = substr($endpoint, 0, strrpos($endpoint, '/'));
        $command = substr($endpoint, strrpos($endpoint, '/') + 1);
        $rows = $this->menus[$menu] ?? [];

        $matches = fn () => array_values(array_filter($rows, function ($row) use ($where) {
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));

        $this->response = match (true) {
            // print with =count-only= answers "!done =ret=<n>"
            $command === 'print' && array_key_exists('count-only', $set) => ['after' => ['ret' => (string) count($matches())]],
            $command === 'print' => $matches(),
            default => match ($command) {
            'add' => $this->add($menu, $set),
            'set' => $this->set($menu, $set),
            'remove' => $this->remove($menu, $set['.id']),
            'move' => $this->move($menu, $set['numbers'], $set['destination']),
            default => [],
            },
        };

        return $this;
    }

    public function read(): array
    {
        return $this->response;
    }

    /** Rows of a menu matching all given attributes. */
    public function find(string $menu, array $attrs = []): array
    {
        return array_values(array_filter($this->menus[$menu] ?? [], function ($row) use ($attrs) {
            foreach ($attrs as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Commands sent to an endpoint such as "/tool/fetch". */
    public function sent(string $endpoint): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->commands, fn ($c) => $c[0] === $endpoint)));
    }

    private function add(string $menu, array $set): array
    {
        $before = $set['place-before'] ?? null;
        unset($set['place-before']);
        $row = ['.id' => '*'.dechex($this->nextId++)] + $set;

        $rows = $this->menus[$menu] ?? [];
        $at = $before === null ? false : array_search($before, array_column($rows, '.id'), true);
        array_splice($rows, $at === false ? count($rows) : $at, 0, [$row]);
        $this->menus[$menu] = $rows;

        return ['after' => ['ret' => $row['.id']]];
    }

    private function set(string $menu, array $set): array
    {
        // Single-row menus (/ip/dns, /system/identity, ...) are set without an id.
        if (! isset($set['.id'])) {
            $this->menus[$menu][0] = $set + ($this->menus[$menu][0] ?? ['.id' => '*0']);

            return [];
        }

        foreach ($this->menus[$menu] ?? [] as $i => $row) {
            if ($row['.id'] === $set['.id']) {
                $this->menus[$menu][$i] = $set + $row;
            }
        }

        return [];
    }

    private function remove(string $menu, string $id): array
    {
        $this->menus[$menu] = array_values(array_filter($this->menus[$menu] ?? [], fn ($row) => $row['.id'] !== $id));

        return [];
    }

    private function move(string $menu, string $id, string $before): array
    {
        $rows = $this->menus[$menu];
        $row = $rows[array_search($id, array_column($rows, '.id'), true)];
        $rows = array_values(array_filter($rows, fn ($r) => $r['.id'] !== $id));
        array_splice($rows, array_search($before, array_column($rows, '.id'), true), 0, [$row]);
        $this->menus[$menu] = $rows;

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Conia\Registry;

use Closure;
use Conia\Registry\Entry;
use Conia\Registry\Resolver;
use Conia\Registry\Exception\NotFoundException;
use Psr\Container\ContainerInterface as PsrContainer;

/**
 * @psalm-api
 *
 * @psalm-type EntryArray = array<never, never>|array<string, Entry>
 */
class Registry implements PsrContainer
{
    protected Resolver $resolver;

    /** @psalm-var EntryArray */
    protected array $entries = [];

    /** @psalm-var array<never, never>|array<non-empty-string, self> */
    protected array $tags = [];

    public function __construct(
        protected readonly ?PsrContainer $container = null,
        public readonly bool $autowire = true,
        protected readonly string $tag = '',
        protected readonly ?Registry $parent = null,
    ) {
        if ($container) {
            $this->add(PsrContainer::class, $container);
            $this->add($container::class, $container);
        } else {
            $this->add(PsrContainer::class, $this);
        }

        $this->add(Registry::class, $this);
        $this->resolver = new Resolver($this);
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]) || $this->container?->has($id);
    }

    /** @psalm-return list<string> */
    public function entries(bool $includeRegistry = false): array
    {
        $keys = array_keys($this->entries);

        if ($includeRegistry) {
            return $keys;
        }

        return array_values(array_filter($keys, function ($item) {
            return $item !== PsrContainer::class && !is_subclass_of($item, PsrContainer::class);
        }));
    }

    public function entry(string $id): Entry
    {
        return $this->entries[$id];
    }

    public function get(string $id): mixed
    {
        $entry = $this->entries[$id] ?? null;

        if ($entry) {
            return $this->resolveEntry($entry);
        }

        if ($this->container && $this->container->has($id)) {
            return $this->container->get($id);
        }

        // We are in a tag. Unregistered entries should always
        // be registered on the root.
        if ($this->parent) {
            return $this->parent->get($id);
        }

        // Autowiring: $id does not exists as an entry in the registry
        if ($this->autowire && class_exists($id)) {
            return $this->resolver->autowire($id);
        }

        throw new NotFoundException('Unresolvable id: ' . $id);
    }

    /**
     * @psalm-param non-empty-string $id
     */
    public function add(
        string $id,
        mixed $value = null,
    ): Entry {
        $entry = new Entry($id, $value ?? $id);
        $this->entries[$id] = $entry;

        return $entry;
    }

    /** @psalm-param non-empty-string $tag */
    public function tag(string $tag): Registry
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = new self(tag: $tag, parent: $this);
        }

        return $this->tags[$tag];
    }

    public function new(string $id, mixed ...$args): object
    {
        $entry = $this->entries[$id] ?? null;

        if ($entry) {
            /** @var mixed */
            $value = $entry->definition();

            if (is_string($value)) {
                if (interface_exists($value)) {
                    return $this->new($value, ...$args);
                }

                if (class_exists($value)) {
                    /** @psalm-suppress MixedMethodCall */
                    return new $value(...$args);
                }
            }
        }

        if (class_exists($id)) {
            /** @psalm-suppress MixedMethodCall */
            return new $id(...$args);
        }

        throw new NotFoundException('Cannot instantiate ' . $id);
    }

    protected function callAndReify(Entry $entry, mixed $value): mixed
    {
        foreach ($entry->getCalls() as $call) {
            $methodToResolve = $call->method;

            /** @psalm-var callable */
            $callable = [$value, $methodToResolve];
            $args = $this->resolver->resolveCallableArgs($callable, $call->args);
            $callable(...$args);
        }

        if ($entry->shouldReify()) {
            $entry->set($value);
        }

        return $value;
    }

    protected function resolveEntry(Entry $entry): mixed
    {
        if ($entry->shouldReturnAsIs()) {
            return $entry->definition();
        }

        /** @var mixed - the current value, instantiated or definition */
        $value = $entry->get();

        if (is_string($value)) {
            if (class_exists($value)) {
                $constructor = $entry->getConstructor();
                $args = $entry->getArgs();

                if (isset($args)) {
                    // Don't autowire if $args are given
                    if ($args instanceof Closure) {
                        /** @psalm-var array<string, mixed> */
                        $args = $args(...$this->resolver->resolveCallableArgs($args));

                        return $this->callAndReify(
                            $entry,
                            $this->resolver->autowire($value, $args)
                        );
                    }

                    return $this->callAndReify(
                        $entry,
                        $this->resolver->autowire($value, $args, $constructor)
                    );
                }

                return $this->callAndReify(
                    $entry,
                    $this->resolver->autowire($value, [], $constructor)
                );
            }

            if (isset($this->entries[$value])) {
                return $this->get($value);
            }
        }

        if ($value instanceof Closure) {
            $args = $entry->getArgs();

            if (is_null($args)) {
                $args = $this->resolver->resolveCallableArgs($value);
            } elseif ($args instanceof Closure) {
                /** @var array<string, mixed> */
                $args = $args();
            }

            /** @var mixed */
            $result = $value(...$args);

            return $this->callAndReify($entry, $result);
        }

        if (is_object($value)) {
            return $value;
        }

        throw new NotFoundException('Unresolvable id: ' . (string)$value);
    }
}

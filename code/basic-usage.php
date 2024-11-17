<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Conia\Registry\Registry;

class Value
{
    public function get(): string
    {
        return 'string';
    }
}

$registry = new Registry();
$registry->add(Value::class);

$value = $registry->get(Value::class);

assert($value instanceof Value);
assert($value->get() === 'string');

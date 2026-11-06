<?php

declare(strict_types=1);

// Developer-only bridge for Python to independently decode PHP setter output.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$messages = [];
foreach (\Arcjet\Tests\Protocol\FixtureMessages::all() as $name => $message) {
    $messages[$name] = base64_encode($message->serializeToString());
}
echo json_encode($messages, JSON_THROW_ON_ERROR), "\n";

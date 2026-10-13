<?php

declare(strict_types=1);

// Reads capabilities and the model catalog; performs no remote mutations.
$envai = require __DIR__.'/config.php';
$capabilities = $envai->capabilities()->getCapabilities();

printf("ATP v%d\n", $capabilities->protocolVersion);
printf("Features: %s\n", implode(', ', $capabilities->features));
printf("Model entries: %d\n", count($envai->models()->listModels()->data));

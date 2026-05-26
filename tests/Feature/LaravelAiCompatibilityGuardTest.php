<?php

use Illuminate\Support\Str;

it('does not import Laravel AI provider gateway internals from source', function () {
    $root = realpath(__DIR__.'/../../src');
    $files = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php');

    $forbidden = [
        'Laravel\\Ai\\Gateway\\',
        'Laravel\\Ai\\Providers\\',
        'Laravel\\Ai\\Gateway;',
        'Laravel\\Ai\\Providers;',
        'CanStreamUsingVercelProtocol',
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        foreach ($forbidden as $pattern) {
            expect(Str::contains($contents, $pattern))
                ->toBeFalse("Forbidden Laravel AI internal import [{$pattern}] found in {$file->getPathname()}.");
        }
    }
});

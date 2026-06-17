<?php

it('aliases dev main to the active 0.15 development line', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['branch-alias']['dev-main'] ?? null)->toBe('0.15-dev');
});

<?php

/**
 * @return list<string>
 */
function translationKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        is_array($value) ? array_push($keys, ...translationKeys($value, $prefix.$key.'.')) : $keys[] = $prefix.$key;
    }

    sort($keys);

    return $keys;
}

it('translates every line in Italian too', function (string $file) {
    $english = require __DIR__.'/../../resources/lang/en/'.$file.'.php';
    $italian = require __DIR__.'/../../resources/lang/it/'.$file.'.php';

    expect(translationKeys($italian))->toBe(translationKeys($english));
})->with(['enums', 'fields', 'import', 'notifications', 'ui', 'validation']);

it('defines every key the code and the views use', function () {
    $english = [];

    foreach (glob(__DIR__.'/../../resources/lang/en/*.php') as $file) {
        foreach (translationKeys(require $file) as $key) {
            $english[] = basename($file, '.php').'.'.$key;
        }
    }

    $sources = [];

    foreach ([__DIR__.'/../../src', __DIR__.'/../../resources/views'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $sources[] = $file->getPathname();
            }
        }
    }
    $missing = [];

    foreach ($sources as $source) {
        preg_match_all("/sales-crm::((?:ui|fields|enums|validation|import|notifications)\\.[a-z0-9_.]*[a-z0-9_])(?=['\"])/", (string) file_get_contents($source), $matches);

        foreach ($matches[1] as $key) {
            // A key built at runtime (`ui.settings.tab_'.$tab`) needs at least one translation with that prefix.
            $prefix = str_ends_with($key, '_') ? $key : $key.'.';

            if (! in_array($key, $english, true) && ! collect($english)->contains(fn (string $known): bool => str_starts_with($known, $prefix))) {
                $missing[] = $key.' ('.basename($source).')';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

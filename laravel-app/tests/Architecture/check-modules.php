<?php

// Standalone architecture check: no framework, database or PHPUnit required.
// php tests/Architecture/check-modules.php [optional Modules directory]

$root = $argv[1] ?? dirname(__DIR__, 2).'/app/Modules';
$allowed = [
    'Users' => [],
    'Inventory' => [],
    'Products' => ['Inventory'],
    'Orders' => ['Users', 'Products', 'Inventory'],
    'Notifications' => ['Users', 'Orders'],
];
$errors = [];
$edges = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
    $owner = explode('/', $relative)[0];
    if (! isset($allowed[$owner])) {
        if ($relative !== 'ModuleServiceProvider.php') {
            $errors[] = "$relative: module $owner needs an explicit dependency policy";
        }
        continue;
    }
    $source = file_get_contents($file->getPathname());
    // Relationship targets must resolve to models inside the owning module.
    // This also catches a leftover relation after its foreign import is removed.
    preg_match('/namespace ([^;]+);/', $source, $namespace);
    preg_match_all('/^use ([^;]+);/m', $source, $imports);
    $aliases = [];
    foreach ($imports[1] as $import) {
        $parts = preg_split('/ as /i', $import);
        $name = $parts[1] ?? substr($parts[0], strrpos($parts[0], '\\') + 1);
        $aliases[$name] = $parts[0];
    }
    preg_match_all('/->(?:belongsTo|hasMany|hasOne|belongsToMany)\((\w+)::class/', $source, $relations);
    foreach ($relations[1] as $target) {
        $class = $aliases[$target] ?? ($namespace[1].'\\'.$target);
        $prefix = 'App\\Modules\\'.$owner.'\\';
        $path = $root.'/'.$owner.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (! str_starts_with($class, $prefix) || ! is_file($path)) {
            $errors[] = "$relative: relationship target $class is not an owned model";
        }
    }
    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $name = ltrim($token[1], '\\');
        if (! str_starts_with($name, 'App\\Modules\\')) {
            continue;
        }
        $parts = explode('\\', substr($name, strlen('App\\Modules\\')));
        $target = $parts[0];
        if ($target === $owner || $target === 'ModuleServiceProvider') {
            continue;
        }
        $edges[$owner][$target] = true;
        $public = ($parts[1] ?? '') === 'PublicApi'
            || ($target === 'Inventory' && in_array($parts[1] ?? '', ['Contracts', 'Data', 'Exceptions'], true));
        if (! $public || ! in_array($target, $allowed[$owner], true)) {
            $errors[] = "$relative:{$token[2]}: $owner may not access $name";
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($errors)).PHP_EOL);
    exit(1);
}
echo "PASS: module references use public interfaces and the acyclic dependency graph.\n";
foreach ($edges as $owner => $targets) {
    echo $owner.' -> '.implode(', ', array_keys($targets)).PHP_EOL;
}

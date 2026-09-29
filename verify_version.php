<?php
/**
 * Fails the build unless git tag == $this->version == config.xml <version>.
 * Usage: php verify_version.php <moduleDir> <tag>
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

[$self, $moduleDir, $tag] = array_pad($argv, 3, null);
if ($moduleDir === null || $tag === null) {
    fwrite(STDERR, "Usage: php verify_version.php <moduleDir> <tag>\n");
    exit(2);
}

$module = basename(rtrim($moduleDir, '/'));
$tagVersion = tpu_normalize_tag($tag);

try {
    $moduleVersion = tpu_module_version($moduleDir, $module);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$errors = [];
if ($moduleVersion !== $tagVersion) {
    $errors[] = sprintf('Tag %s != $this->version %s', $tagVersion, $moduleVersion);
}
$configVersion = tpu_config_version($moduleDir);
if ($configVersion !== null && $configVersion !== $moduleVersion) {
    $errors[] = sprintf('config.xml %s != $this->version %s', $configVersion, $moduleVersion);
}

if ($errors !== []) {
    fwrite(STDERR, "Version mismatch:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Version OK: $moduleVersion\n");
exit(0);

<?php
/**
 * Fails the build unless git tag == $this->version == config.xml <version>.
 * Usage: php verify_version.php <moduleDir> <tag> [release.json]
 *
 * The module's technical name comes from release.json ("module") when available,
 * so it works when the module sits at the repo root (moduleDir ".") and the repo
 * is named differently from the module.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

[$self, $moduleDir, $tag, $releaseJson] = array_pad($argv, 4, null);
if ($moduleDir === null || $tag === null) {
    fwrite(STDERR, "Usage: php verify_version.php <moduleDir> <tag> [release.json]\n");
    exit(2);
}

$module = null;
$rjPath = $releaseJson ?: rtrim($moduleDir, '/') . '/release.json';
if (is_file($rjPath)) {
    $rj = json_decode((string) file_get_contents($rjPath), true);
    if (is_array($rj) && !empty($rj['module']) && is_string($rj['module'])) {
        $module = $rj['module'];
    }
}
if ($module === null) {
    $resolved = realpath($moduleDir);
    $module = basename($resolved !== false ? $resolved : rtrim($moduleDir, '/'));
}
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

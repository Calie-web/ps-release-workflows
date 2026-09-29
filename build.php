<?php
/**
 * CI build: reproducible package ZIP + metadata.json.
 * Usage: php build.php <moduleDir> <release.json> <outputDir>
 * Env: SOURCE_DATE_EPOCH (optional), GITHUB_SHA (optional), TAG (optional).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

[$self, $moduleDir, $releaseJson, $outDir] = array_pad($argv, 4, null);
if ($moduleDir === null || $releaseJson === null || $outDir === null) {
    fwrite(STDERR, "Usage: php build.php <moduleDir> <release.json> <outputDir>\n");
    exit(2);
}

$release = json_decode((string) file_get_contents($releaseJson), true);
if (!is_array($release)) {
    fwrite(STDERR, "Invalid release.json\n");
    exit(2);
}

$tag = getenv('TAG');
if (is_string($tag) && $tag !== '') {
    $release['tag'] = $tag;
    $release['version_from_tag'] = tpu_normalize_tag($tag);
}
$commit = getenv('GITHUB_SHA');
if (is_string($commit) && $commit !== '') {
    $release['commit'] = $commit;
}
$epochEnv = getenv('SOURCE_DATE_EPOCH');
$epoch = is_string($epochEnv) && $epochEnv !== '' ? (int) $epochEnv : (int) strtotime((string) ($release['released_at'] ?? 'now'));

try {
    $result = tpu_build_package($moduleDir, $release, $outDir, $epoch);
} catch (Throwable $e) {
    fwrite(STDERR, 'Build failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Built %s\nsha256=%s\n", basename($result['zip']), $result['sha256']));
exit(0);

<?php

declare(strict_types=1);

/**
 * Self-contained release helpers used by the CI build scripts.
 *
 * Intentionally namespace- and dependency-free (beyond ext-zip / ext-sodium /
 * ext-tokenizer) so a module's own CI can run it without pulling in the updater's
 * code. It only DEFINES functions — safe to include from tests.
 */

/**
 * Reads a `$this-><prop> = '...'` literal from module source via the tokenizer
 * (never executes the code). Returns null if not found.
 */
function tpu_extract_assignment(string $php, string $prop): ?string
{
    $tokens = [];
    foreach (token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            $tokens[] = [$t[0], $t[1]];
        } else {
            $tokens[] = [-1, $t];
        }
    }

    $n = count($tokens);
    for ($i = 0; $i + 4 < $n; $i++) {
        if ($tokens[$i][0] === T_VARIABLE && $tokens[$i][1] === '$this'
            && $tokens[$i + 1][0] === T_OBJECT_OPERATOR
            && $tokens[$i + 2][0] === T_STRING && $tokens[$i + 2][1] === $prop
            && $tokens[$i + 3][0] === -1 && $tokens[$i + 3][1] === '='
            && $tokens[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING) {
            $raw = $tokens[$i + 4][1];
            $inner = substr($raw, 1, -1);

            return $raw[0] === "'"
                ? str_replace(["\\\\", "\\'"], ["\\", "'"], $inner)
                : str_replace(["\\\\", '\\"'], ["\\", '"'], $inner);
        }
    }

    return null;
}

function tpu_module_version(string $moduleDir, string $module): string
{
    $file = rtrim($moduleDir, '/') . '/' . $module . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("Main module file not found: $file");
    }
    $version = tpu_extract_assignment((string) file_get_contents($file), 'version');
    if ($version === null) {
        throw new RuntimeException('Could not read $this->version from ' . $file);
    }

    return $version;
}

function tpu_config_version(string $moduleDir): ?string
{
    $file = rtrim($moduleDir, '/') . '/config.xml';
    if (!is_file($file)) {
        return null;
    }
    $xml = @simplexml_load_string((string) file_get_contents($file));
    if ($xml === false || !isset($xml->version)) {
        return null;
    }

    return trim((string) $xml->version);
}

/** Normalises a git tag ("v1.2.0" -> "1.2.0"). */
function tpu_normalize_tag(string $tag): string
{
    return ltrim(trim($tag), 'vV');
}

/** Derives the channel from the version's pre-release part, unless overridden. */
function tpu_channel_for(string $version, ?string $override): string
{
    if ($override !== null && $override !== '') {
        return $override;
    }
    if (preg_match('/-.*dev/i', $version)) {
        return 'dev';
    }
    if (str_contains($version, '-')) {
        return 'beta';
    }

    return 'stable';
}

/**
 * @param array<string,mixed> $release
 * @param string[]            $extraExcludes
 *
 * Pruning (applied at any depth, including inside vendor/) drops non-runtime
 * cruft: VCS/CI/editor dirs, test, docs and example dirs, coverage output, and
 * common doc/config files (*.md, phpunit/phpstan/psalm configs, dotfiles). This
 * plus `composer install --no-dev` keeps packages small. Anything a package needs
 * at runtime is never matched; a `keep` glob (from release.json) force-includes a
 * path if a dependency is unusual.
 *
 * @param string[] $extraExcludes fnmatch patterns (matched on the full path and on the basename)
 * @param string[] $keep          fnmatch patterns that force-include a path despite pruning
 *
 * @return string[] relative paths, sorted, forward-slash separated
 */
function tpu_collect_files(string $moduleDir, array $extraExcludes = [], array $keep = []): array
{
    // Directory names dropped wherever they appear (non-runtime by convention).
    $pruneDirs = [
        '.git', '.github', '.gitlab', '.circleci', 'node_modules', '.idea', '.vscode',
        'tests', 'Tests', 'test', 'docs', 'doc', 'examples', 'example', 'benchmarks', 'coverage',
    ];
    // Basename globs dropped wherever they appear.
    $pruneFiles = [
        '*.md', '.gitignore', '.gitattributes', '.editorconfig', '.gitmodules',
        '.php-cs-fixer.php', '.php-cs-fixer.dist.php', '.php_cs', '.php_cs.dist',
        'phpunit.xml', 'phpunit.xml.dist', '.phpunit.result.cache',
        'phpstan.neon', 'phpstan.neon.dist', 'psalm.xml', 'psalm.xml.dist',
        '.travis.yml', '.scrutinizer.yml', 'Makefile', '.DS_Store', 'release.json',
    ];

    $root = rtrim($moduleDir, '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir() || $item->isLink()) {
            continue;
        }
        $rel = ltrim(substr($item->getPathname(), strlen($root)), '/');
        $base = basename($rel);

        // A keep pattern overrides all pruning.
        if (tpu_matches_any($rel, $base, $keep)) {
            $files[] = $rel;
            continue;
        }

        if (array_intersect(explode('/', $rel), $pruneDirs) !== []) {
            continue;
        }
        foreach ($pruneFiles as $glob) {
            if (fnmatch($glob, $base)) {
                continue 2;
            }
        }
        if (tpu_matches_any($rel, $base, $extraExcludes)) {
            continue;
        }

        $files[] = $rel;
    }

    sort($files);

    return $files;
}

/**
 * @param string[] $patterns
 */
function tpu_matches_any(string $rel, string $base, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        $pattern = (string) $pattern;
        if ($pattern === '') {
            continue;
        }
        if (fnmatch($pattern, $rel) || fnmatch($pattern, $base)) {
            return true;
        }
    }

    return false;
}

/**
 * Builds the package ZIP (root folder == module name) and metadata.json.
 *
 * Reproducible: files are added in sorted order with a fixed mtime (epoch), so
 * repeated builds of identical content produce an identical archive.
 *
 * @param array<string,mixed> $release  parsed release.json (+ injected tag/commit)
 *
 * @return array{zip:string,metadata:string,sha256:string,version:string,module:string}
 */
function tpu_build_package(string $moduleDir, array $release, string $outDir, int $epoch): array
{
    $module = (string) ($release['module'] ?? basename(rtrim($moduleDir, '/')));
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $module)) {
        throw new RuntimeException("Invalid module technical name: $module");
    }
    $version = tpu_module_version($moduleDir, $module);

    if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        throw new RuntimeException("Cannot create output dir: $outDir");
    }

    $excludes = [];
    if (isset($release['exclude']) && is_array($release['exclude'])) {
        foreach ($release['exclude'] as $p) {
            $excludes[] = (string) $p;
        }
    }
    $keep = [];
    if (isset($release['keep']) && is_array($release['keep'])) {
        foreach ($release['keep'] as $p) {
            $keep[] = (string) $p;
        }
    }
    $files = tpu_collect_files($moduleDir, $excludes, $keep);
    if ($files === []) {
        throw new RuntimeException('No files to package.');
    }

    $zipPath = rtrim($outDir, '/') . '/' . $module . '-' . $version . '.zip';
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create zip: $zipPath");
    }
    $root = rtrim($moduleDir, '/');
    foreach ($files as $rel) {
        $abs = $root . '/' . $rel;
        @touch($abs, $epoch);
        $zip->addFile($abs, $module . '/' . $rel);
        if (method_exists($zip, 'setMtimeName')) {
            /** @phpstan-ignore-next-line runtime feature-detect */
            $zip->setMtimeName($module . '/' . $rel, $epoch);
        }
    }
    $zip->close();

    $sha = hash_file('sha256', $zipPath);
    if ($sha === false) {
        throw new RuntimeException('Failed to hash package.');
    }
    $size = (int) filesize($zipPath);

    $metadata = tpu_build_metadata($release, $module, $version, basename($zipPath), $size, $sha, $epoch);
    $metaPath = rtrim($outDir, '/') . '/metadata.json';
    file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    return ['zip' => $zipPath, 'metadata' => $metaPath, 'sha256' => $sha, 'version' => $version, 'module' => $module];
}

/**
 * @param array<string,mixed> $release
 *
 * @return array<string,mixed>
 */
function tpu_build_metadata(array $release, string $module, string $version, string $packageName, int $size, string $sha, int $epoch): array
{
    $ps = is_array($release['prestashop'] ?? null) ? $release['prestashop'] : [];
    $php = is_array($release['php'] ?? null) ? $release['php'] : [];
    $requires = is_array($release['requires'] ?? null) ? $release['requires'] : [];

    return [
        'schema_version' => 1,
        'module' => $module,
        'version' => $version,
        'tag' => (string) ($release['tag'] ?? ('v' . $version)),
        'commit' => (string) ($release['commit'] ?? ''),
        'channel' => tpu_channel_for($version, isset($release['channel']) ? (string) $release['channel'] : null),
        'released_at' => (string) ($release['released_at'] ?? gmdate('Y-m-d', $epoch)),
        'prestashop' => [
            'min' => (string) ($ps['min'] ?? '8.0.0'),
            'max' => (string) ($ps['max'] ?? '8.99.99'),
        ],
        'php' => [
            'min' => (string) ($php['min'] ?? '8.1'),
            'max' => (string) ($php['max'] ?? '8.4'),
        ],
        'requires' => [
            'modules' => is_array($requires['modules'] ?? null) ? $requires['modules'] : (object) [],
            'php_ext' => is_array($requires['php_ext'] ?? null) ? array_values($requires['php_ext']) : [],
        ],
        'min_updater_version' => (string) ($release['min_updater_version'] ?? '1.0.0'),
        'package' => [
            'name' => $packageName,
            'size' => $size,
            'sha256' => $sha,
        ],
        'db_migrations' => (bool) ($release['db_migrations'] ?? false),
        'db_backward_compatible' => (bool) ($release['db_backward_compatible'] ?? false),
        'touches_overrides' => (bool) ($release['touches_overrides'] ?? false),
        'changelog' => (string) ($release['changelog'] ?? ''),
    ];
}

/** Signs $contents with an Ed25519 secret key (base64), returning a base64 signature. */
function tpu_sign(string $contents, string $secretKeyBase64): string
{
    $secret = base64_decode(trim($secretKeyBase64), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Invalid Ed25519 secret key.');
    }

    return base64_encode(sodium_crypto_sign_detached($contents, $secret));
}

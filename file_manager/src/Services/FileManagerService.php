<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Services;

/**
 * FileManagerService
 *
 * All file operations for the File Manager plugin. This class deliberately has
 * NO dependency on the CMS so the security-critical logic can be tested alone.
 *
 * Security model
 * --------------
 *  - Every path the client sends is a *relative* path under a fixed root.
 *  - Segments equal to ".." are rejected outright (never "resolved").
 *  - Existing paths are checked with realpath(), so symlinks that point outside
 *    the root are refused.
 *  - Operations that act on the entry itself (delete / rename / move) resolve the
 *    PARENT directory and never follow the entry, so a symlink is removed as a
 *    link and its target is never touched.
 *  - "Protected" paths (default: .env, .git) can be viewed but never modified.
 *  - Uploads and archive extraction refuse executable extensions unless the
 *    site owner explicitly allows them.
 */
class FileManagerService
{
    public const DEFAULTS = [
        // Root-relative paths that can be read but never changed.
        'protected_paths'    => ['.env', '.git'],
        // Refused on upload and when extracting archives.
        'blocked_extensions' => ['php', 'phtml', 'phar', 'pht', 'php3', 'php4', 'php5', 'php7', 'phps', 'htaccess', 'htpasswd'],
        'max_edit_bytes'     => 2097152,    // 2 MB
        'max_upload_bytes'   => 52428800,   // 50 MB
        'max_extract_bytes'  => 524288000,  // 500 MB total uncompressed
        'max_archive_files'  => 20000,
        'max_list_items'     => 5000,
        'read_only'          => false,
    ];

    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'env', 'log', 'csv', 'tsv',
        'html', 'htm', 'twig', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'vue', 'svg',
        'php', 'phtml', 'inc', 'module', 'install', 'theme', 'sql', 'sh', 'bash', 'py', 'rb', 'go', 'java', 'c',
        'h', 'cpp', 'rs', 'lock', 'gitignore', 'htaccess', 'htpasswd', 'editorconfig', 'toml', 'tpl', 'lst',
    ];

    private const IMAGE_EXTENSIONS   = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'avif'];
    private const ARCHIVE_EXTENSIONS = ['zip', 'tar', 'gz', 'tgz', 'bz2', '7z', 'rar'];

    private string $root;
    private array $o;

    public function __construct(string $root, array $options = [])
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new FileManagerException('The configured root directory does not exist.', 500);
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if ($real === '' || preg_match('#^[A-Za-z]:$#', $real)) {
            throw new FileManagerException('The filesystem root cannot be used as the file manager root.', 500);
        }
        $this->root = $real;

        $merged = array_replace(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));
        $merged['protected_paths'] = array_values(array_filter(array_map(
            fn($p) => strtolower(trim(str_replace('\\', '/', (string) $p), '/')),
            (array) $merged['protected_paths']
        ), fn($p) => $p !== ''));
        $merged['blocked_extensions'] = array_values(array_filter(array_map(
            fn($e) => strtolower(ltrim(trim((string) $e), '.')),
            (array) $merged['blocked_extensions']
        ), fn($e) => $e !== ''));
        $this->o = $merged;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->o['read_only'];
    }

    public function getLimits(): array
    {
        return [
            'max_edit_bytes'   => (int) $this->o['max_edit_bytes'],
            'max_upload_bytes' => (int) $this->o['max_upload_bytes'],
            'read_only'        => $this->isReadOnly(),
            'zip_available'    => class_exists(\ZipArchive::class),
        ];
    }

    // ------------------------------------------------------------------
    //  Path handling (the security core)
    // ------------------------------------------------------------------

    /** Normalise a client path to "a/b/c" ('' = root). Rejects traversal. */
    public function normalizeRel(string $rel): string
    {
        if (preg_match('/[\x00-\x1F]/', $rel)) {
            throw new FileManagerException('Invalid characters in path.', 400);
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new FileManagerException('Path traversal is not allowed.', 400);
            }
            if (DIRECTORY_SEPARATOR === '\\' && preg_match('/[:*?"<>|]/', $seg)) {
                throw new FileManagerException('Invalid characters in path.', 400);
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /** Validate a single file/folder name (no slashes, no dots-only, etc.). */
    public function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new FileManagerException('Invalid name.', 400);
        }
        if (preg_match('/[\x00-\x1F\/\\\\]/', $name)) {
            throw new FileManagerException('Names cannot contain slashes or control characters.', 400);
        }
        if (strlen($name) > 255) {
            throw new FileManagerException('Name is too long (max 255 bytes).', 400);
        }
        if (str_ends_with($name, '.')) {
            throw new FileManagerException('Names cannot end with a dot.', 400);
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/[:*?"<>|]/', $name)) {
            throw new FileManagerException('Name contains characters not allowed on this system.', 400);
        }
        return $name;
    }

    private function isInside(string $absNormalized): bool
    {
        return $absNormalized === $this->root || str_starts_with($absNormalized, $this->root . '/');
    }

    private function relFromRoot(string $absNormalized): string
    {
        return ltrim(substr($absNormalized, strlen($this->root)), '/');
    }

    private function norm(string $p): string
    {
        return rtrim(str_replace('\\', '/', $p), '/');
    }

    /** Resolve an EXISTING path (follows symlinks, must stay inside root). */
    public function resolve(string $rel): string
    {
        $rel = $this->normalizeRel($rel);
        $abs = $rel === '' ? $this->root : $this->root . '/' . $rel;
        $real = realpath($abs);
        if ($real === false) {
            throw new FileManagerException('Path not found.', 404);
        }
        $real = $this->norm($real);
        if (!$this->isInside($real)) {
            throw new FileManagerException('Access denied: outside the allowed root.', 403);
        }
        return $real;
    }

    /**
     * Resolve an existing entry WITHOUT following it (for delete/rename/move).
     * Returns [absolutePathOfEntry, normalisedRel].
     */
    private function resolveEntry(string $rel): array
    {
        $rel = $this->normalizeRel($rel);
        if ($rel === '') {
            throw new FileManagerException('The root folder cannot be modified.', 403);
        }
        [$parentRel, $name] = $this->splitRel($rel);
        $parent = $this->resolve($parentRel);
        $abs = $parent . '/' . $name;
        if (!file_exists($abs) && !is_link($abs)) {
            throw new FileManagerException('Path not found.', 404);
        }
        return [$abs, $rel];
    }

    /** Resolve a NEW (possibly not yet existing) child inside a directory. */
    private function resolveNew(string $parentRel, string $name): array
    {
        $name = $this->validateName($name);
        $parent = $this->resolve($parentRel);
        if (!is_dir($parent)) {
            throw new FileManagerException('Destination is not a folder.', 400);
        }
        $rel = ltrim($this->relFromRoot($parent) . '/' . $name, '/');
        return [$parent . '/' . $name, $rel, $parent];
    }

    private function splitRel(string $rel): array
    {
        $i = strrpos($rel, '/');
        return $i === false ? ['', $rel] : [substr($rel, 0, $i), substr($rel, $i + 1)];
    }

    // ------------------------------------------------------------------
    //  Protection rules
    // ------------------------------------------------------------------

    private function isProtected(string $rel): bool
    {
        $rel = strtolower($rel);
        foreach ($this->o['protected_paths'] as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** True when $rel is protected OR contains a protected path (e.g. deleting a parent of .git). */
    private function touchesProtected(string $rel): bool
    {
        if ($this->isProtected($rel)) {
            return true;
        }
        $rel = strtolower($rel);
        foreach ($this->o['protected_paths'] as $p) {
            if ($rel === '' || str_starts_with($p, $rel . '/')) {
                return true;
            }
        }
        return false;
    }

    private function assertMutable(string $rel, ?string $absReal = null): void
    {
        if ($this->isReadOnly()) {
            throw new FileManagerException('The file manager is in read-only mode.', 403);
        }
        if ($this->touchesProtected($rel)) {
            throw new FileManagerException('"' . $rel . '" is protected and cannot be modified.', 403);
        }
        if ($absReal !== null) {
            $real = $this->norm($absReal);
            if ($this->isInside($real) && $this->touchesProtected($this->relFromRoot($real))) {
                throw new FileManagerException('The target of "' . $rel . '" is protected and cannot be modified.', 403);
            }
        }
    }

    private function assertNotBlockedName(string $name): void
    {
        $segments = explode('.', strtolower($name));
        array_shift($segments); // the base name itself is not an extension
        foreach ($segments as $seg) {
            if ($seg !== '' && in_array($seg, $this->o['blocked_extensions'], true)) {
                throw new FileManagerException('Files of type ".' . $seg . '" are blocked by the file manager settings.', 415);
            }
        }
    }

    // ------------------------------------------------------------------
    //  Listing / searching
    // ------------------------------------------------------------------

    public function list(string $rel, bool $showHidden = true): array
    {
        $dir = $this->resolve($rel);
        if (!is_dir($dir)) {
            throw new FileManagerException('Not a folder.', 400);
        }
        $handle = @opendir($dir);
        if ($handle === false) {
            throw new FileManagerException('Cannot read this folder (permission denied).', 403);
        }

        $relDir = $this->relFromRoot($dir);
        $items = [];
        $truncated = false;
        $invalid = 0;
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!$showHidden && $name[0] === '.') {
                continue;
            }
            if (preg_match('//u', $name) !== 1) {
                $invalid++;   // cannot be shown or addressed safely over JSON
                continue;
            }
            if (count($items) >= (int) $this->o['max_list_items']) {
                $truncated = true;
                break;
            }
            $items[] = $this->describe($dir . '/' . $name, $name, $relDir);
        }
        closedir($handle);

        usort($items, function (array $a, array $b): int {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return [
            'path'        => $relDir,
            'parent'      => $relDir === '' ? null : $this->splitRel($relDir)[0],
            'breadcrumbs' => $this->breadcrumbs($relDir),
            'items'       => $items,
            'truncated'   => $truncated,
            'hidden_invalid' => $invalid,
            'writable'    => !$this->isReadOnly() && is_writable($dir) && !$this->isProtected($relDir),
        ];
    }

    public function search(string $rel, string $query, bool $showHidden = true, int $limit = 200): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new FileManagerException('Enter something to search for.', 400);
        }
        $start = $this->resolve($rel);
        if (!is_dir($start)) {
            throw new FileManagerException('Not a folder.', 400);
        }
        $needle = strtolower($query);
        $results = [];
        $scanned = 0;
        $capped = false;
        $stack = [[$start, 0]];

        while ($stack) {
            [$dir, $depth] = array_pop($stack);
            $names = @scandir($dir);
            if ($names === false) {
                continue;
            }
            $relDir = $this->relFromRoot($dir);
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (!$showHidden && $name[0] === '.') {
                    continue;
                }
                if (preg_match('//u', $name) !== 1) {
                    continue;
                }
                if (++$scanned > 50000) {
                    $capped = true;
                    break 2;
                }
                $abs = $dir . '/' . $name;
                if (str_contains(strtolower($name), $needle)) {
                    $results[] = $this->describe($abs, $name, $relDir);
                    if (count($results) >= $limit) {
                        $capped = true;
                        break 2;
                    }
                }
                if ($depth < 20 && is_dir($abs) && !is_link($abs)) {
                    $stack[] = [$abs, $depth + 1];
                }
            }
        }

        usort($results, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        return ['query' => $query, 'items' => $results, 'truncated' => $capped];
    }

    private function breadcrumbs(string $rel): array
    {
        $crumbs = [['name' => 'Home', 'path' => '']];
        $acc = '';
        foreach ($rel === '' ? [] : explode('/', $rel) as $seg) {
            $acc = ltrim($acc . '/' . $seg, '/');
            $crumbs[] = ['name' => $seg, 'path' => $acc];
        }
        return $crumbs;
    }

    private function describe(string $abs, string $name, string $relDir): array
    {
        $rel = ltrim($relDir . '/' . $name, '/');
        $isLink = is_link($abs);
        $linkOk = true;
        $linkTarget = null;
        if ($isLink) {
            $linkTarget = @readlink($abs) ?: null;
            $real = @realpath($abs);
            $linkOk = $real !== false && $this->isInside($this->norm($real));
        }
        $isDir = is_dir($abs) && (!$isLink || $linkOk);
        $st = @lstat($abs) ?: [];
        $stTarget = ($isLink && $linkOk) ? (@stat($abs) ?: $st) : $st;
        $size = $isDir ? null : (int) ($stTarget['size'] ?? 0);
        $perms = @fileperms($abs);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' && $name !== '' && $name[0] === '.') {
            $ext = strtolower(substr($name, 1));
        }

        $kind = 'file';
        if ($isDir) {
            $kind = 'folder';
        } elseif (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            $kind = 'image';
        } elseif (in_array($ext, self::ARCHIVE_EXTENSIONS, true)) {
            $kind = 'archive';
        } elseif (in_array($ext, self::TEXT_EXTENSIONS, true)) {
            $kind = 'code';
        }

        $editable = !$isDir && $linkOk
            && ($size ?? 0) <= (int) $this->o['max_edit_bytes']
            && ($kind === 'code' || ($ext === '' && $kind === 'file') || $kind === 'file' && in_array($ext, self::TEXT_EXTENSIONS, true));

        return [
            'name'       => $name,
            'path'       => $rel,
            'is_dir'     => $isDir,
            'kind'       => $kind,
            'ext'        => $ext,
            'size'       => $size,
            'mtime'      => (int) ($stTarget['mtime'] ?? 0),
            'perms'      => $perms === false ? null : substr(sprintf('%o', $perms), -4),
            'is_link'    => $isLink,
            'link_ok'    => $linkOk,
            'link_target'=> $isLink ? $linkTarget : null,
            'protected'  => $this->isProtected($rel),
            'editable'   => $editable,
            'zip'        => $ext === 'zip',
        ];
    }

    // ------------------------------------------------------------------
    //  Read / write file contents
    // ------------------------------------------------------------------

    public function read(string $rel): array
    {
        $abs = $this->resolve($rel);
        if (!is_file($abs)) {
            throw new FileManagerException('Not a file.', 400);
        }
        $size = (int) filesize($abs);
        if ($size > (int) $this->o['max_edit_bytes']) {
            throw new FileManagerException('This file is too large to edit in the browser (limit ' . $this->humanBytes((int) $this->o['max_edit_bytes']) . ').', 413);
        }
        $content = @file_get_contents($abs);
        if ($content === false) {
            throw new FileManagerException('Cannot read this file (permission denied).', 403);
        }
        if (str_contains($content, "\0")) {
            throw new FileManagerException('This looks like a binary file and cannot be edited as text.', 415);
        }
        if ($content !== '' && preg_match('//u', $content) !== 1) {
            throw new FileManagerException('This file is not valid UTF-8 text and cannot be edited safely.', 415);
        }
        $relReal = $this->relFromRoot($abs);
        return [
            'path'      => $relReal,
            'name'      => basename($abs),
            'content'   => $content,
            'size'      => $size,
            'mtime'     => (int) filemtime($abs),
            'writable'  => !$this->isReadOnly() && is_writable($abs) && !$this->touchesProtected($relReal) && !$this->touchesProtected($this->normalizeRel($rel)),
            'protected' => $this->touchesProtected($relReal) || $this->touchesProtected($this->normalizeRel($rel)),
        ];
    }

    public function save(string $rel, string $content, ?int $expectedMtime = null, bool $force = false): array
    {
        $normalized = $this->normalizeRel($rel);
        $abs = $this->resolve($rel);
        if (!is_file($abs)) {
            throw new FileManagerException('Not a file.', 400);
        }
        $this->assertMutable($normalized, $abs);
        if (strlen($content) > (int) $this->o['max_edit_bytes']) {
            throw new FileManagerException('Content exceeds the editor size limit.', 413);
        }
        clearstatcache(true, $abs);
        if (!$force && $expectedMtime !== null && (int) filemtime($abs) !== $expectedMtime) {
            throw new FileManagerException('This file changed on disk after you opened it. Reload it, or save again to overwrite.', 409);
        }
        if (!is_writable($abs)) {
            throw new FileManagerException('This file is not writable (check permissions).', 403);
        }

        $perm = @fileperms($abs);
        $tmp = $abs . '.fm-' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written === strlen($content)) {
            if ($perm !== false) {
                @chmod($tmp, $perm & 0777);
            }
            if (!@rename($tmp, $abs)) {
                @unlink($tmp);
                $written = false;
            }
        } else {
            @unlink($tmp);
            $written = false;
        }
        if ($written === false) {
            // Directory not writable (or rename failed): fall back to in-place write.
            if (@file_put_contents($abs, $content, LOCK_EX) !== strlen($content)) {
                throw new FileManagerException('Could not write the file.', 500);
            }
        }
        clearstatcache(true, $abs);
        return ['mtime' => (int) filemtime($abs), 'size' => strlen($content)];
    }

    // ------------------------------------------------------------------
    //  Create / rename / delete / move / copy
    // ------------------------------------------------------------------

    public function createFile(string $parentRel, string $name): array
    {
        [$abs, $rel] = $this->resolveNew($parentRel, $name);
        $this->assertMutable($rel);
        if (file_exists($abs) || is_link($abs)) {
            throw new FileManagerException('"' . basename($abs) . '" already exists.', 409);
        }
        $h = @fopen($abs, 'x');
        if ($h === false) {
            throw new FileManagerException('Could not create the file (permission denied?).', 403);
        }
        fclose($h);
        @chmod($abs, 0644);
        return ['path' => $rel];
    }

    public function createDir(string $parentRel, string $name): array
    {
        [$abs, $rel] = $this->resolveNew($parentRel, $name);
        $this->assertMutable($rel);
        if (file_exists($abs) || is_link($abs)) {
            throw new FileManagerException('"' . basename($abs) . '" already exists.', 409);
        }
        if (!@mkdir($abs, 0755)) {
            throw new FileManagerException('Could not create the folder (permission denied?).', 403);
        }
        return ['path' => $rel];
    }

    public function rename(string $rel, string $newName): array
    {
        [$abs, $relN] = $this->resolveEntry($rel);
        $newName = $this->validateName($newName);
        [$parentRel] = $this->splitRel($relN);
        $newRel = ltrim($parentRel . '/' . $newName, '/');
        $this->assertMutable($relN);
        $this->assertMutable($newRel);

        $target = dirname($abs) . '/' . $newName;
        $sameEntryDifferentCase = strcasecmp(basename($abs), $newName) === 0;
        if ((file_exists($target) || is_link($target)) && !$sameEntryDifferentCase) {
            throw new FileManagerException('"' . $newName . '" already exists here.', 409);
        }
        if (!@rename($abs, $target)) {
            throw new FileManagerException('Could not rename (permission denied?).', 403);
        }
        return ['path' => $newRel];
    }

    /** @param string[] $rels */
    public function delete(array $rels): array
    {
        $done = [];
        $errors = [];
        foreach ($rels as $rel) {
            try {
                [$abs, $relN] = $this->resolveEntry((string) $rel);
                $this->assertMutable($relN);
                $this->removeTree($abs);
                $done[] = $relN;
            } catch (FileManagerException $e) {
                $errors[] = ['path' => (string) $rel, 'error' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'errors' => $errors];
    }

    /** Never follows symlinks. */
    private function removeTree(string $abs, int $depth = 0): void
    {
        if ($depth > 60) {
            throw new FileManagerException('Folder nesting is too deep to delete safely.', 400);
        }
        if (is_link($abs)) {
            // On Windows a directory symlink must be removed with rmdir().
            $ok = (DIRECTORY_SEPARATOR === '\\' && is_dir($abs)) ? @rmdir($abs) : @unlink($abs);
            if (!$ok) {
                throw new FileManagerException('Could not delete "' . basename($abs) . '".', 403);
            }
            return;
        }
        if (is_dir($abs)) {
            $names = @scandir($abs);
            if ($names === false) {
                throw new FileManagerException('Cannot read "' . basename($abs) . '".', 403);
            }
            foreach ($names as $n) {
                if ($n !== '.' && $n !== '..') {
                    $this->removeTree($abs . '/' . $n, $depth + 1);
                }
            }
            if (!@rmdir($abs)) {
                throw new FileManagerException('Could not delete folder "' . basename($abs) . '".', 403);
            }
            return;
        }
        if (!@unlink($abs)) {
            throw new FileManagerException('Could not delete "' . basename($abs) . '".', 403);
        }
    }

    /** @param string[] $rels */
    public function move(array $rels, string $destRel): array
    {
        $destAbs = $this->resolve($destRel);
        if (!is_dir($destAbs)) {
            throw new FileManagerException('Destination is not a folder.', 400);
        }
        $destRelN = $this->relFromRoot($destAbs);
        $done = [];
        $errors = [];
        foreach ($rels as $rel) {
            try {
                [$abs, $relN] = $this->resolveEntry((string) $rel);
                $name = basename($abs);
                $newRel = ltrim($destRelN . '/' . $name, '/');
                $this->assertMutable($relN);
                $this->assertMutable($newRel);
                if (!is_link($abs) && is_dir($abs)) {
                    $srcReal = $this->norm((string) realpath($abs));
                    if ($destAbs === $srcReal || str_starts_with($destAbs . '/', $srcReal . '/')) {
                        throw new FileManagerException('Cannot move a folder into itself.', 400);
                    }
                }
                $target = $destAbs . '/' . $name;
                if (file_exists($target) || is_link($target)) {
                    throw new FileManagerException('"' . $name . '" already exists in the destination.', 409);
                }
                if (!@rename($abs, $target)) {
                    throw new FileManagerException('Could not move "' . $name . '".', 403);
                }
                $done[] = $newRel;
            } catch (FileManagerException $e) {
                $errors[] = ['path' => (string) $rel, 'error' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'errors' => $errors];
    }

    /** @param string[] $rels */
    public function copy(array $rels, string $destRel): array
    {
        $destAbs = $this->resolve($destRel);
        if (!is_dir($destAbs)) {
            throw new FileManagerException('Destination is not a folder.', 400);
        }
        $destRelN = $this->relFromRoot($destAbs);
        $done = [];
        $errors = [];
        foreach ($rels as $rel) {
            try {
                $srcRelN = $this->normalizeRel((string) $rel);
                $srcAbs = $this->resolve($srcRelN);   // copying follows a link's target, but only inside root
                if ($srcAbs === $this->root) {
                    throw new FileManagerException('The root folder cannot be copied.', 400);
                }
                if (is_dir($srcAbs) && ($destAbs === $srcAbs || str_starts_with($destAbs . '/', $srcAbs . '/'))) {
                    throw new FileManagerException('Cannot copy a folder into itself.', 400);
                }
                $name = $this->uniqueName($destAbs, basename($srcAbs));
                $newRel = ltrim($destRelN . '/' . $name, '/');
                $this->assertMutable($newRel);
                $budget = (int) $this->o['max_archive_files'];
                $this->copyTree($srcAbs, $destAbs . '/' . $name, 0, $budget);
                $done[] = $newRel;
            } catch (FileManagerException $e) {
                $errors[] = ['path' => (string) $rel, 'error' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'errors' => $errors];
    }

    private function copyTree(string $src, string $dst, int $depth, int &$budget): void
    {
        if ($depth > 60 || --$budget < 0) {
            throw new FileManagerException('Too many files or folders too deep to copy in one go.', 400);
        }
        if (is_link($src)) {
            return; // symlinks inside a copied tree are skipped, never followed
        }
        if (is_dir($src)) {
            if (!@mkdir($dst, 0755) && !is_dir($dst)) {
                throw new FileManagerException('Could not create "' . basename($dst) . '".', 403);
            }
            foreach (@scandir($src) ?: [] as $n) {
                if ($n !== '.' && $n !== '..') {
                    $this->copyTree($src . '/' . $n, $dst . '/' . $n, $depth + 1, $budget);
                }
            }
            return;
        }
        if (!@copy($src, $dst)) {
            throw new FileManagerException('Could not copy "' . basename($src) . '".', 403);
        }
    }

    private function uniqueName(string $dirAbs, string $name): string
    {
        if (!file_exists($dirAbs . '/' . $name) && !is_link($dirAbs . '/' . $name)) {
            return $name;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
        for ($i = 1; $i < 1000; $i++) {
            $candidate = $base . ($i === 1 ? ' copy' : ' copy ' . $i) . ($ext === '' ? '' : '.' . $ext);
            if (!file_exists($dirAbs . '/' . $candidate) && !is_link($dirAbs . '/' . $candidate)) {
                return $candidate;
            }
        }
        throw new FileManagerException('Could not find a free name for the copy.', 409);
    }

    // ------------------------------------------------------------------
    //  Upload / download
    // ------------------------------------------------------------------

    /**
     * @param array<int,array{name:string,tmp_name:string,size:int,error:int}> $files
     */
    public function upload(array $files, string $destRel, bool $overwrite = false): array
    {
        $destAbs = $this->resolve($destRel);
        if (!is_dir($destAbs)) {
            throw new FileManagerException('Destination is not a folder.', 400);
        }
        if (!is_writable($destAbs)) {
            throw new FileManagerException('The destination folder is not writable.', 403);
        }
        $destRelN = $this->relFromRoot($destAbs);
        $done = [];
        $errors = [];

        foreach ($files as $f) {
            $orig = (string) ($f['name'] ?? '');
            try {
                $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_OK) {
                    throw new FileManagerException($this->uploadErrorMessage($err), 400);
                }
                $name = $this->validateName(basename(str_replace('\\', '/', $orig)));
                $this->assertNotBlockedName($name);
                $newRel = ltrim($destRelN . '/' . $name, '/');
                $this->assertMutable($newRel);
                if ((int) ($f['size'] ?? 0) > (int) $this->o['max_upload_bytes']) {
                    throw new FileManagerException('File is larger than the ' . $this->humanBytes((int) $this->o['max_upload_bytes']) . ' upload limit.', 413);
                }
                $target = $destAbs . '/' . $name;
                if (is_link($target)) {
                    throw new FileManagerException('"' . $name . '" is a symbolic link and cannot be overwritten.', 409);
                }
                if (file_exists($target) && !$overwrite) {
                    throw new FileManagerException('"' . $name . '" already exists.', 409);
                }
                if (is_dir($target)) {
                    throw new FileManagerException('A folder named "' . $name . '" already exists.', 409);
                }
                if (!$this->moveIncoming((string) $f['tmp_name'], $target)) {
                    throw new FileManagerException('Could not store "' . $name . '".', 500);
                }
                @chmod($target, 0644);
                $done[] = $newRel;
            } catch (FileManagerException $e) {
                $errors[] = ['name' => $orig, 'error' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'errors' => $errors];
    }

    /** Overridable in tests. Only genuine PHP uploads are ever accepted. */
    protected function moveIncoming(string $tmp, string $dest): bool
    {
        return is_uploaded_file($tmp) && move_uploaded_file($tmp, $dest);
    }

    private function uploadErrorMessage(int $err): string
    {
        return match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the size limit set by the server (upload_max_filesize / post_max_size).',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload (temp directory problem).',
            default              => 'Upload failed.',
        };
    }

    /** Absolute path of a regular file for download. */
    public function downloadPath(string $rel): string
    {
        $abs = $this->resolve($rel);
        if (!is_file($abs)) {
            throw new FileManagerException('Only files can be downloaded. Compress the folder first.', 400);
        }
        if (!is_readable($abs)) {
            throw new FileManagerException('This file is not readable.', 403);
        }
        return $abs;
    }

    // ------------------------------------------------------------------
    //  Archives
    // ------------------------------------------------------------------

    private function requireZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileManagerException('The PHP "zip" extension is not installed on this server.', 501);
        }
    }

    /** @param string[] $rels */
    public function zip(array $rels, string $destRel, string $archiveName): array
    {
        $this->requireZip();
        if ($rels === []) {
            throw new FileManagerException('Select at least one file or folder.', 400);
        }
        $archiveName = $this->validateName($archiveName);
        if (!str_ends_with(strtolower($archiveName), '.zip')) {
            $archiveName .= '.zip';
        }
        [$finalAbs, $finalRel] = $this->resolveNew($destRel, $archiveName);
        $this->assertMutable($finalRel);
        if (file_exists($finalAbs) || is_link($finalAbs)) {
            throw new FileManagerException('"' . $archiveName . '" already exists.', 409);
        }

        $tmp = $finalAbs . '.fm-' . bin2hex(random_bytes(4)) . '.tmp';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new FileManagerException('Could not create the archive (permission denied?).', 403);
        }
        $count = 0;
        try {
            foreach ($rels as $rel) {
                $abs = $this->resolve((string) $rel);
                if ($abs === $this->root) {
                    throw new FileManagerException('The root folder cannot be compressed in one go; select its contents instead.', 400);
                }
                $this->zipAdd($zip, $abs, basename($abs), 0, $count);
            }
            if ($count === 0) {
                throw new FileManagerException('Nothing to compress.', 400);
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($tmp);
            throw $e;
        }
        if (!$zip->close() || !@rename($tmp, $finalAbs)) {
            @unlink($tmp);
            throw new FileManagerException('Could not write the archive.', 500);
        }
        return ['path' => $finalRel, 'entries' => $count];
    }

    private function zipAdd(\ZipArchive $zip, string $abs, string $entryName, int $depth, int &$count): void
    {
        if (is_link($abs)) {
            return;
        }
        if ($depth > 60 || $count > (int) $this->o['max_archive_files']) {
            throw new FileManagerException('Too many files to compress in one archive.', 400);
        }
        if (is_dir($abs)) {
            $zip->addEmptyDir($entryName);
            $count++;
            foreach (@scandir($abs) ?: [] as $n) {
                if ($n !== '.' && $n !== '..') {
                    $this->zipAdd($zip, $abs . '/' . $n, $entryName . '/' . $n, $depth + 1, $count);
                }
            }
            return;
        }
        if (is_readable($abs)) {
            $zip->addFile($abs, $entryName);
            $count++;
        }
    }

    public function unzip(string $rel, string $destRel, bool $overwrite = false): array
    {
        $this->requireZip();
        $archive = $this->resolve($rel);
        if (!is_file($archive)) {
            throw new FileManagerException('Not a file.', 400);
        }
        $destAbs = $this->resolve($destRel);
        if (!is_dir($destAbs)) {
            throw new FileManagerException('Destination is not a folder.', 400);
        }
        if ($this->isReadOnly()) {
            throw new FileManagerException('The file manager is in read-only mode.', 403);
        }
        $destRelN = $this->relFromRoot($destAbs);
        if ($this->isProtected($destRelN)) {
            throw new FileManagerException('The destination is protected and cannot be modified.', 403);
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new FileManagerException('This is not a valid zip archive.', 415);
        }

        $extracted = 0;
        $skipped = [];
        $bytes = 0;
        $maxBytes = (int) $this->o['max_extract_bytes'];
        $total = $zip->numFiles;

        try {
            if ($total > (int) $this->o['max_archive_files']) {
                throw new FileManagerException('Archive contains too many entries.', 413);
            }
            for ($i = 0; $i < $total; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $reason = $this->zipEntryProblem($zip, $i, $entry);
                if ($reason !== null) {
                    $skipped[] = ['entry' => $entry, 'reason' => $reason];
                    continue;
                }
                $isDir = str_ends_with($entry, '/');
                $clean = $this->normalizeRel($entry);
                $target = $destAbs . '/' . $clean;

                if (!$isDir) {
                    try {
                        $this->assertNotBlockedName(basename($clean));
                    } catch (FileManagerException $e) {
                        $skipped[] = ['entry' => $entry, 'reason' => 'blocked file type'];
                        continue;
                    }
                }

                // Logical protection check BEFORE anything is created.
                $logicalRel = ltrim($destRelN . '/' . $clean, '/');
                if ($this->isProtected($logicalRel)) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'protected path'];
                    continue;
                }
                // Create the parent folder only after proving it stays inside the root (symlink-safe).
                $realParent = $this->ensureDirInside($isDir ? $target : dirname($target));
                if ($realParent === null) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'unsafe or protected destination'];
                    continue;
                }
                if ($isDir) {
                    $extracted++;
                    continue;
                }

                $out = $realParent . '/' . basename($clean);
                if ($this->isProtected($this->relFromRoot($out))) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'protected path'];
                    continue;
                }
                if (is_link($out)) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'target is a symlink'];
                    continue;
                }
                if (file_exists($out) && !$overwrite) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'already exists'];
                    continue;
                }
                $in = $zip->getStream($entry);
                $fh = $in ? @fopen($out, 'wb') : false;
                if (!$in || !$fh) {
                    $skipped[] = ['entry' => $entry, 'reason' => 'could not write'];
                    continue;
                }
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $maxBytes) {
                        fclose($fh);
                        fclose($in);
                        @unlink($out);
                        throw new FileManagerException('Extraction stopped: archive is larger than ' . $this->humanBytes($maxBytes) . ' uncompressed.', 413);
                    }
                    fwrite($fh, $chunk);
                }
                fclose($fh);
                fclose($in);
                @chmod($out, 0644);
                $extracted++;
            }
        } finally {
            $zip->close();
        }
        return ['extracted' => $extracted, 'skipped' => $skipped];
    }

    /**
     * mkdir -p that is safe against symlinks: the nearest EXISTING ancestor is
     * resolved and verified (inside root, not protected) before anything is
     * created, and the final directory is verified again. Returns its real path.
     */
    private function ensureDirInside(string $dir): ?string
    {
        $probe = $dir;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            $probe = $parent;
        }
        $realProbe = realpath($probe);
        if ($realProbe === false) {
            return null;
        }
        $realProbe = $this->norm($realProbe);
        if (!$this->isInside($realProbe) || !is_dir($realProbe) || $this->isProtected($this->relFromRoot($realProbe))) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $real = realpath($dir);
        if ($real === false) {
            return null;
        }
        $real = $this->norm($real);
        if (!$this->isInside($real) || $this->isProtected($this->relFromRoot($real))) {
            return null;
        }
        return $real;
    }

    /** Returns a reason string if the entry must not be extracted, else null. */
    private function zipEntryProblem(\ZipArchive $zip, int $index, string $entry): ?string
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            return 'invalid name';
        }
        $n = str_replace('\\', '/', $entry);
        if (str_starts_with($n, '/') || preg_match('#^[A-Za-z]:#', $n)) {
            return 'absolute path';
        }
        foreach (explode('/', $n) as $seg) {
            if ($seg === '..') {
                return 'path traversal';
            }
        }
        if (preg_match('/[\x00-\x1F]/', $n)) {
            return 'invalid characters';
        }
        // Skip symbolic links stored in the archive.
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
            if ((($attr >> 16) & 0170000) === 0120000) {
                return 'symbolic link';
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    //  Permissions
    // ------------------------------------------------------------------

    public function chmod(string $rel, string $mode): array
    {
        if (!preg_match('/^[0-7]{3,4}$/', $mode) || (strlen($mode) === 4 && $mode[0] !== '0')) {
            throw new FileManagerException('Enter permissions as 3 or 4 octal digits, e.g. 644 or 0755. Setuid/setgid/sticky bits are not allowed.', 400);
        }
        $normalized = $this->normalizeRel($rel);
        $abs = $this->resolve($rel);
        if ($abs === $this->root) {
            throw new FileManagerException('The root folder cannot be modified.', 403);
        }
        $this->assertMutable($normalized, $abs);
        if (!@chmod($abs, octdec($mode))) {
            throw new FileManagerException('Could not change permissions (not the owner?).', 403);
        }
        clearstatcache(true, $abs);
        return ['perms' => substr(sprintf('%o', (int) fileperms($abs)), -4)];
    }

    // ------------------------------------------------------------------

    private function humanBytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
            if ($b < 1024 || $i === 3) {
                return ($i === 0 ? (string) $b : rtrim(rtrim(number_format($b, 1, '.', ''), '0'), '.')) . ' ' . $u;
            }
            $b /= 1024;
        }
        return (string) $b;
    }
}

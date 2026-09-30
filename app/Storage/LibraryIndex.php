<?php

namespace App\Storage;

use App\Providers\Contracts\EmbeddingClient;
use PDO;

/**
 * Postgres-backed index over skills and prompts, for search.
 *
 * ## THE FILES REMAIN THE SOURCE OF TRUTH — this is the load-bearing decision
 *
 * A skill is instructions injected into a prompt. `SkillLibrary` therefore refuses project-local
 * skill directories *unconditionally*: a repository you clone must not be able to hand the agent
 * standing instructions the moment you run Paider inside it. That is the clone-to-RCE boundary,
 * and it is why skills are read from `~/.paider/skills` and nowhere else.
 *
 * Moving skills into a writable database would quietly dissolve that boundary. A database has no
 * filesystem permission to check, and a "sync from project" affordance — the obvious thing to
 * build here — is exactly a remote-code-execution vector wearing a convenience label. So:
 *
 *   - The DB is a DERIVED index. Deleting the table loses nothing; re-indexing rebuilds it.
 *   - The ONLY import path is a path the caller supplies, and it is checked against the same
 *     refusal list before a single row is written. A project directory is not importable, full
 *     stop — the same rule, enforced in the one new place it could have been forgotten.
 *   - There is deliberately NO "sync from current project" method, and no env var that turns one
 *     on. Its absence is the security property. If a future change adds one, it must re-prove
 *     the boundary rather than assume this comment was read.
 *
 * This is why the answer to "should skills live in Postgres" is "as a queryable index, never as
 * an authority": the database gets to be fast without getting to be trusted.
 */
final class LibraryIndex
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Idempotent schema. Called by the import/search paths, never in a constructor side effect,
     * so that merely holding an index object never writes to the database.
     */
    public function ensureSchema(): void
    {
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');

        $previous = (string) $this->pdo->query('SHOW search_path')->fetchColumn();
        $target = trim((string) strtok($previous, ',')) ?: 'public';
        $this->pdo->exec("SET search_path TO {$target}, public");

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS library_items (
                    id TEXT PRIMARY KEY,
                    kind TEXT NOT NULL,
                    name TEXT NOT NULL,
                    description TEXT,
                    body TEXT NOT NULL,
                    model TEXT,
                    dimensions INTEGER,
                    embedding vector,
                    source TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS library_items_kind_idx ON library_items (kind)');
        } finally {
            $this->pdo->exec("SET search_path TO {$previous}");
        }
    }

    /**
     * Import items that have already passed the trust check.
     *
     * Takes a {@see VettedItems}, NOT a raw array. That is the whole point and it is not
     * ceremony: this method used to accept `array $items` and write whatever it was given, which
     * meant the "the writer cannot skip the check" claim in this class's own docblock was false —
     * one public call with a hand-built array bypassed the clone-to-RCE boundary that
     * {@see LibraryImporter} exists to enforce. Requiring a value object only the importer can
     * construct makes the claim structural instead of documented.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importVetted(VettedItems $vetted, ?EmbeddingClient $embedder = null): array
    {
        $this->ensureSchema();

        $items = $vetted->all();

        if ($items === []) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $upsert = $this->pdo->prepare(
            'INSERT INTO library_items
                (id, kind, name, description, body, model, dimensions, embedding, source, created_at, updated_at)
             VALUES (:id, :kind, :name, :description, :body, :model, :dimensions, :embedding, :source, :now, :now)
             ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                description = EXCLUDED.description,
                body = EXCLUDED.body,
                model = EXCLUDED.model,
                dimensions = EXCLUDED.dimensions,
                embedding = EXCLUDED.embedding,
                source = EXCLUDED.source,
                updated_at = EXCLUDED.updated_at'
        );

        $vectors = null;

        if ($embedder !== null) {
            $vectors = $embedder->embed(array_map(
                static fn (array $i): string => $i['name']."\n".($i['description'] ?? '')."\n".$i['body'],
                $items
            ));
        }

        $imported = 0;
        $skipped = 0;
        $now = gmdate('c');

        foreach ($items as $position => $item) {
            if (! is_string($item['name'] ?? null) || $item['name'] === '' || ! is_string($item['body'] ?? null)) {
                $skipped++;

                continue;
            }

            $vector = $vectors[$position] ?? null;

            $upsert->execute([
                'id' => $item['kind'].':'.$item['name'],
                'kind' => $item['kind'],
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'body' => $item['body'],
                'model' => $embedder?->model(),
                'dimensions' => is_array($vector) ? count($vector) : null,
                'embedding' => is_array($vector) ? RagStore::toVectorLiteral($vector) : null,
                'source' => $item['source'] ?? null,
                'now' => $now,
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Vector search across the library, optionally narrowed to one kind.
     *
     * @return array<int, array{kind: string, name: string, description: ?string, body: string, distance: float}>
     */
    public function search(string $query, EmbeddingClient $embedder, ?string $kind = null, int $limit = 5): array
    {
        $this->ensureSchema();

        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $vectors = $embedder->embed([$query]);
        $vector = $vectors[0] ?? null;

        if (! is_array($vector) || $vector === []) {
            return [];
        }

        $previous = (string) $this->pdo->query('SHOW search_path')->fetchColumn();
        $target = trim((string) strtok($previous, ',')) ?: 'public';
        $this->pdo->exec("SET search_path TO {$target}, public");

        try {
            // CAST(... AS vector) is required: a bound parameter is untyped text, and Postgres
            // cannot resolve the <=> overload for it. The type is also resolved at PREPARE time,
            // which is the other reason this runs with public on the search_path.
            $sql = 'SELECT kind, name, description, body, embedding <=> CAST(:query AS vector) AS distance
                    FROM library_items
                    WHERE dimensions = :dimensions AND embedding IS NOT NULL';

            $params = [
                'query' => RagStore::toVectorLiteral($vector),
                'dimensions' => count($vector),
                'limit' => max(1, min(50, $limit)),
            ];

            if ($kind !== null) {
                $sql .= ' AND kind = :kind';
                $params['kind'] = $kind;
            }

            $sql .= ' ORDER BY embedding <=> CAST(:query AS vector) LIMIT :limit';

            $statement = $this->pdo->prepare($sql);

            foreach ($params as $name => $value) {
                $statement->bindValue(':'.$name, $value, $name === 'limit' || $name === 'dimensions' ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $statement->execute();

            return array_map(static fn (array $row): array => [
                'kind' => (string) $row['kind'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'body' => (string) $row['body'],
                'distance' => (float) $row['distance'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $this->pdo->exec("SET search_path TO {$previous}");
        }
    }

    /**
     * Names only, for `paider` to list without a model round-trip.
     *
     * @return array<int, array{kind: string, name: string, description: ?string}>
     */
    public function list(?string $kind = null): array
    {
        $this->ensureSchema();

        $sql = 'SELECT kind, name, description FROM library_items';
        $params = [];

        if ($kind !== null) {
            $sql .= ' WHERE kind = :kind';
            $params['kind'] = $kind;
        }

        $sql .= ' ORDER BY kind, name';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['kind'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function forget(string $kind, string $name): bool
    {
        $this->ensureSchema();

        $statement = $this->pdo->prepare('DELETE FROM library_items WHERE kind = :kind AND name = :name');
        $statement->execute(['kind' => $kind, 'name' => $name]);

        return $statement->rowCount() > 0;
    }

    /**
     * The refusal list, re-exported so the importer and the library cannot drift apart.
     *
     * Two call sites checking two literals is how a boundary quietly stops holding.
     *
     * @return array<int, string>
     */
    public static function refusedProjectDirs(): array
    {
        return ['.paider/skills', '.claude/skills', '.paider/prompts', '.claude/prompts'];
    }

    /**
     * May this path be imported from?
     *
     * The threat is not "inside the project directory" — it is "anywhere a cloned repository, an
     * untrusted archive, or a hostile checkout can write". A path comparison against getcwd() would
     * let every one of those through the moment it lives in /tmp, which is exactly where a cloned
     * repo often is, and would give a false sense of a boundary that is not there.
     *
     * So the rule is capability-shaped instead of location-shaped:
     *
     *   - Anything inside the current project is refused outright.
     *   - Any path with the shape of a project-local agent config (.paider/… or .claude/…) is
     *     refused anywhere on the filesystem, because that is what a repository plants.
     *   - Everything else — a path the user names explicitly, outside a project, in their own
     *     home — is allowed, because the user pointing at it IS the authorisation. There is no
     *     ambient "scan everything" path; nothing runs without someone naming this directory.
     *
     * Named as a question with an answer so a caller cannot forget to ask it.
     */
    public static function refusesPath(string $absolutePath): bool
    {
        $real = realpath($absolutePath);

        if ($real === false) {
            // A path that does not exist cannot be vetted; refuse rather than guess.
            return true;
        }

        $cwd = realpath(getcwd() ?: '.');

        if ($cwd !== false && ($real === $cwd || str_starts_with($real, $cwd.DIRECTORY_SEPARATOR))) {
            return true;
        }

        // The operator's OWN Paider directory is exempt from the shape check below, and this is
        // a correction rather than a nicety.
        //
        // The capability-shaped rule ("any path that LOOKS like a project-local agent config is
        // refused anywhere on disk") was written to close a hole: a location check alone let a
        // clone in /tmp straight through. But the shape `.paider/skills` is precisely the shape of
        // the user's own home library — the ONE directory SkillLibrary trusts and calls "the only
        // place skills are discovered from". So the rule also refused the trusted directory, and
        // the method's own docblock ("a path the user names explicitly … in their own home — is
        // allowed") described behaviour the code did not have. Found by a reviewer reading the
        // code against the comment, which is the second time that has happened on this class.
        //
        // The exemption is scoped as tightly as the threat allows: the real path must be INSIDE
        // the resolved ~/.paider. A repository that plants `.paider/skills` inside the project is
        // still refused by the check above, and a path elsewhere on disk with the same tail is
        // still refused by the shape check — only the user's own home library is exempt, and only
        // because naming it is the authorisation.
        if (self::isInsideOwnPaiderHome($real)) {
            return false;
        }

        $relative = str_starts_with($real, $cwd.DIRECTORY_SEPARATOR) && $cwd !== false
            ? substr($real, strlen($cwd) + 1)
            : $real;

        foreach (self::refusedProjectDirs() as $refused) {
            if ($relative === $refused || str_starts_with($relative, $refused.DIRECTORY_SEPARATOR)) {
                return true;
            }

            // Anywhere on disk, a path ending in a project-local agent config directory is the
            // shape a cloned repository plants — in /tmp, in a checkout, in a vendor dir.
            $segments = explode(DIRECTORY_SEPARATOR, $real);
            $depth = count(explode(DIRECTORY_SEPARATOR, trim($refused, DIRECTORY_SEPARATOR)));

            if (count($segments) >= $depth) {
                $tail = implode(DIRECTORY_SEPARATOR, array_slice($segments, -$depth));
                $wanted = trim($refused, DIRECTORY_SEPARATOR);

                if ($tail === $wanted || $tail === $wanted.'/') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is this path inside the OPERATOR's own ~/.paider?
     *
     * Resolves HOME and compares realpaths, so a symlink pointing out of the home directory does
     * not inherit the exemption. Deliberately narrow: it does not exempt the whole home tree, and
     * it does not exempt anything merely shaped like a Paider directory. Only the directory the
     * tool itself owns.
     */
    private static function isInsideOwnPaiderHome(string $realPath): bool
    {
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            return false;
        }

        $own = realpath(rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.paider');

        if ($own === false) {
            // No ~/.paider yet, so nothing can be inside it. Not an error — refusing by default
            // is the safe direction and the caller will hit the shape check instead.
            return false;
        }

        return $realPath === $own || str_starts_with($realPath, $own.DIRECTORY_SEPARATOR);
    }
}

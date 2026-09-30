<?php

namespace App\Storage;

/**
 * A batch of library items that has already passed the trust check.
 *
 * ## Why this class exists
 *
 * The security property this project claims is that a cloned repository cannot get its skills
 * into the index. That claim was FALSE until now, and the way it was false is worth recording.
 *
 * `LibraryIndex::importItems()` was `public` and wrote whatever array it was handed. The trust
 * check lived in `LibraryImporter::fromPath()` — the READER — and the writer's own docblock
 * asserted that "the writer cannot be talked into skipping it by a future caller". It could. One
 * call to a public method with a hand-built array bypassed the boundary entirely, and
 * `LibraryImporter::collect()` was public too, so a caller could read a project directory's
 * skills and feed them in. An adversarial review (gpt-6-luna) found it by reading the code
 * against the comment.
 *
 * The fix is to make the claim structural instead of documented. `VettedItems` has a private
 * constructor and a static factory that is the ONLY way to obtain one, and that factory is called
 * from exactly one place — after `refusesPath()` has run. There is now no public path from a
 * project directory to a row in `library_items`, so a future caller cannot route around the
 * check: to write, you must first hold one of these, and the only way to hold one is to have
 * passed the check.
 *
 * Final, not `readonly` on the array itself (PHP arrays are values, so `readonly` adds nothing
 * here) but with no mutator and a private constructor.
 */
final class VettedItems
{
    /**
     * @param  array<int, array{kind: string, name: string, description?: string, body: string, source?: string}>  $items
     */
    private function __construct(private readonly array $items) {}

    /**
     * The ONLY way to obtain a VettedItems.
     *
     * Named `afterRefusalCheck` rather than something neutral so that every call site states
     * what it is asserting. A reader of this class should be able to enumerate the writers and
     * see that each one is a caller that already refused something.
     *
     * @param  array<int, array{kind: string, name: string, description?: string, body: string, source?: string}>  $items
     */
    public static function afterRefusalCheck(array $items): self
    {
        return new self(array_values($items));
    }

    /**
     * @return array<int, array{kind: string, name: string, description?: string, body: string, source?: string}>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}

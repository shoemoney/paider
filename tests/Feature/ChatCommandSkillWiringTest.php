<?php

use App\Commands\ChatCommand;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Tools\LoadSkillTool;

/**
 * ChatCommand::handle() is the composition root for the whole skill feature — it's the one
 * place that decides whether load_skill gets registered and whether the trust-boundary notice
 * gets shown. A prior review found it completely untested: a mutated `if (false) { $tools[] =
 * new LoadSkillTool; }`, a mutated `$skillsNotice = null;`, and a smuggled-in
 * `ProjectEnv::bool('PAIDER_ALLOW_PROJECT_SKILLS')` gate all left the full suite green, because
 * every existing skill test only ever exercised SkillLibrary in isolation.
 *
 * handle() itself still isn't exercised here — it blocks on a live ChatPrompt REPL loop with no
 * fake-stdin seam in this codebase (see ChatPrompt) — but the wiring decision it makes was
 * pulled out into buildTools(), a plain method reachable without any of that, and the two
 * exact mutations demonstrated against the composition root are pinned below: one behaviourally
 * (buildTools), one by source invariant (the notice call and the absence of a project-readable
 * skills knob), the same source-grep register ProjectSelfAuthorizationTest and SkillLibraryTest
 * already use for wiring that can't be exercised any other way.
 */
function buildChatCommandTools(array $skillIndex): array
{
    $command = new ChatCommand;

    // buildTools() reaches for $this->eventLog (for MemoryTool) — normally set by handle()
    // before buildTools() is ever called; set it directly here since handle() itself isn't
    // exercised in this file (see the file docblock for why).
    $eventLogProperty = new ReflectionProperty(ChatCommand::class, 'eventLog');
    $eventLogProperty->setValue($command, new EventLog(Database::connect(':memory:')));

    $method = new ReflectionMethod(ChatCommand::class, 'buildTools');

    return $method->invoke($command, $skillIndex);
}

test('load_skill is registered when the skill index is non-empty', function () {
    $tools = buildChatCommandTools([['name' => 'a-skill', 'description' => 'does a thing']]);

    $hasLoadSkill = array_any($tools, fn ($tool) => $tool instanceof LoadSkillTool);

    expect($hasLoadSkill)->toBeTrue();
});

test('load_skill is NOT registered when the skill index is empty — no catalogue, no tool doc to pay for', function () {
    $tools = buildChatCommandTools([]);

    $hasLoadSkill = array_any($tools, fn ($tool) => $tool instanceof LoadSkillTool);

    expect($hasLoadSkill)->toBeFalse();
});

test('the composition root still calls the mandatory trust-boundary notice and index build', function () {
    // Source-grep invariant, same pattern as ProjectSelfAuthorizationTest's "no authority
    // setting is read through the project-file path" and SkillLibraryTest's "no project-
    // readable env knob exists in the source": a mutation that deletes the call to
    // refusedProjectSkillsNotice() (silencing the ONE mandated user-facing trust-boundary
    // message) or to SkillLibrary::index() left every other test in the suite green.
    $code = file_get_contents(base_path('app/Commands/ChatCommand.php'));

    expect($code)->toContain('SkillLibrary::refusedProjectSkillsNotice()');
    expect($code)->toContain('SkillLibrary::index()');
});

test('ChatCommand never reads a skills-related setting through the project-file-readable path', function () {
    // The exact hole this whole feature exists to keep shut, one layer up: a cloned repo's
    // .paider/.env granting itself a skills knob would be the PAIDER_YOLO clone-to-RCE bug
    // again, verbatim. Demonstrated concretely by a reviewer inserting
    // `ProjectEnv::bool('PAIDER_ALLOW_PROJECT_SKILLS')` right after the index() call and
    // getting a fully green suite.
    $code = file_get_contents(base_path('app/Commands/ChatCommand.php'));

    expect($code)
        ->not->toContain('ProjectEnv::get(')
        ->not->toContain('ProjectEnv::bool(');
});

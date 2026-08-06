<?php

namespace App\Support;

use App\Storage\ProjectEnv;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\Termwind as TermwindRenderer;

use function Termwind\style;

/** Every colour Paider may emit, named by meaning. Call sites never name a colour. */
enum ColorRole: string
{
    case Accent = 'accent';   // commands, tool-call labels, resume marker
    case Muted = 'muted';    // secondary commentary — must be safe to lose entirely
    case Success = 'success';  // ✓ ok, active preset
    case Error = 'error';    // ✗ failures, validation errors
    case Alert = 'alert';    // inverse warning badge (YOLO)
    case Brand = 'brand';    // PHP purple, decorative only (spinner)
}

/**
 * The single source of colour in Paider. Every call site asks for a ColorRole, never a hex or
 * an SGR code, so the charcoal-rule contrast guarantee lives in exactly one place.
 *
 * THE BUG THIS EXISTS TO PREVENT: Banner.php once hardcoded toilet's --metal gradient, which
 * runs through bright-black (SGR 1;30). On a dark terminal that rendered as charcoal-on-charcoal.
 * Every default colour below is a symbolic ANSI-16 slot (the user's own terminal theme chose it
 * to be readable against their background, so no luminance check applies). Every bundled-theme
 * colour is an absolute hex the THEME author picked for that theme's own known background
 * polarity — dracula/gruvbox-dark/high-contrast for a dark background, solarized-light for a
 * light one — checked against *that* background only (WCAG >=3:1 needs relative luminance
 * <=0.30 against white, >=0.10 against black; nothing here is asked to satisfy both at once,
 * since a dark-themed colour is never shown on a light background or vice versa). Muted is the
 * sole exemption from any check, because its contract is "safe to lose entirely". See the
 * luminance test in PaletteTest.
 */
final class Palette
{
    private static ?bool $enabledCache = null;

    private static bool $themeResolved = false;

    private static ?string $themeCache = null;

    private static bool $booted = false;

    private const DEFAULT_TW = [
        'accent' => 'text-cyan',
        'muted' => 'text-gray',
        'success' => 'text-green',
        'error' => 'text-red',
        'alert' => 'bg-red text-white',
        'brand' => '', // decorative-only, no Termwind consumer today
    ];

    private const DEFAULT_SGR = [
        'accent' => '36',
        'muted' => '90',
        'success' => '32',
        'error' => '31',
        'alert' => '1;37;41',
        'brand' => '38;5;103', // the one absolute default colour — 256-colour, gated separately
    ];

    private const DEFAULT_GRADIENT = ['0;34', '0;94', '0;37', '1;37'];

    /**
     * Hand-picked from each theme's published palette, not converted at runtime. Dracula's own
     * cyan/green (#8be9fd / #50fa7b) and gruvbox's bright green/non-bold red were darkened within
     * the same family rather than borrowed from a different hue, to clear >=3:1 against black —
     * see the luminance test in PaletteTest.
     */
    private const THEMES = [
        'dracula' => [
            'tw' => [
                'accent' => '#3a9ab0',
                'muted' => '#6272a4',
                'success' => '#2fa854',
                'error' => '#ff5555',
                'alert' => ['bg' => '#ff5555', 'fg' => '#f8f8f2'],
                'brand' => '#bd93f9',
            ],
            'sgr' => [
                'accent' => '38;5;67',
                'muted' => '38;5;61',
                'success' => '38;5;35',
                'error' => '38;5;203',
                'alert' => '38;5;255;48;5;203',
                'brand' => '38;5;141',
            ],
        ],
        'gruvbox-dark' => [
            'tw' => [
                'accent' => '#83a598',
                'muted' => '#928374',
                'success' => '#98971a',
                'error' => '#fb4934',
                'alert' => ['bg' => '#fb4934', 'fg' => '#ebdbb2'],
                'brand' => '#d3869b',
            ],
            'sgr' => [
                'accent' => '38;5;108',
                'muted' => '38;5;244',
                'success' => '38;5;100',
                'error' => '38;5;203',
                'alert' => '38;5;187;48;5;203',
                'brand' => '38;5;174',
            ],
        ],
        'solarized-light' => [
            'tw' => [
                'accent' => '#268bd2',
                'muted' => '#93a1a1',
                'success' => '#859900',
                'error' => '#dc322f',
                'alert' => ['bg' => '#dc322f', 'fg' => '#fdf6e3'],
                'brand' => '#6c71c4',
            ],
            'sgr' => [
                'accent' => '38;5;32',
                'muted' => '38;5;247',
                'success' => '38;5;100',
                'error' => '38;5;166',
                'alert' => '38;5;230;48;5;166',
                'brand' => '38;5;62',
            ],
            // The only theme with a LIGHT background: DEFAULT_GRADIENT sweeps toward white,
            // which is 1.08:1 and 1.69:1 against solarized-light's own #fdf6e3 — the exact
            // charcoal-on-charcoal failure mode this class exists to prevent, just inverted.
            // Reuses this theme's own already-vetted 'sgr' colours (no new hex to check),
            // darkest -> brightest by measured luminance: error, brand, accent, success.
            'gradient' => ['38;5;166', '38;5;62', '38;5;32', '38;5;100'],
        ],
        'high-contrast' => [
            'tw' => [
                'accent' => '#1c7ed6',
                'muted' => '#7048b8',
                'success' => '#2f9e44',
                'error' => '#e03131',
                'alert' => ['bg' => '#e03131', 'fg' => '#ffffff'],
                'brand' => '#ae3ec9',
            ],
            'sgr' => [
                'accent' => '38;5;32',
                'muted' => '38;5;61',
                'success' => '38;5;35',
                'error' => '38;5;167',
                'alert' => '38;5;231;48;5;167',
                'brand' => '38;5;134',
            ],
        ],
    ];

    /**
     * The single capability gate. Resolution order (first hit wins):
     * real PAIDER_COLOR env > real NO_COLOR env > .env-file PAIDER_COLOR > .env-file NO_COLOR
     * > TERM=dumb > !stream_isatty(STDOUT) > true.
     * Cached for the process lifetime; forget() clears (mirrors ProjectEnv).
     *
     * Real env vars are checked ahead of BOTH file-sourced keys, not just their own file
     * counterpart: ProjectEnv::get()'s per-key precedence (real env beats a file value for the
     * SAME key) doesn't stop a project's checked-in `.paider/.env` PAIDER_COLOR=1 from silently
     * re-enabling colour for an operator who set NO_COLOR in their own real shell — exactly what
     * no-color.org exists to prevent, and the opposite of ProjectEnv's own authority-vs-
     * convenience split (see its class docblock).
     */
    public static function enabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }

        // Explicit user intent beats even NO_COLOR — our FORCE_COLOR equivalent. Only a REAL
        // env var counts as "the user's intent" here; a file-sourced PAIDER_COLOR is handled
        // below, after the operator's own real NO_COLOR has had first say.
        $realColor = ProjectEnv::fromEnvironment('PAIDER_COLOR');

        if ($realColor !== null) {
            return self::$enabledCache = (bool) filter_var($realColor, FILTER_VALIDATE_BOOL);
        }

        if (ProjectEnv::fromEnvironment('NO_COLOR') !== null) {
            return self::$enabledCache = false;
        }

        $explicit = ProjectEnv::get('PAIDER_COLOR');

        if ($explicit !== null) {
            return self::$enabledCache = (bool) filter_var($explicit, FILTER_VALIDATE_BOOL);
        }

        $noColor = ProjectEnv::get('NO_COLOR');

        if ($noColor !== null && $noColor !== '') {
            return self::$enabledCache = false;
        }

        if (getenv('TERM') === 'dumb') {
            return self::$enabledCache = false;
        }

        if (! stream_isatty(STDOUT)) {
            return self::$enabledCache = false;
        }

        return self::$enabledCache = true;
    }

    /**
     * Termwind class fragment for a role: 'text-cyan' by default, 'paider-accent'
     * under a named theme, '' when disabled. Compose straight into class="" strings —
     * an empty token is harmless. (Named tw, not class: Foo::class is reserved.)
     * Alert returns a compound fragment: 'bg-red text-white' / 'paider-alert'.
     */
    public static function tw(ColorRole $role): string
    {
        if (! self::enabled()) {
            return '';
        }

        // Must run before returning a 'paider-<role>' class name — that class only exists once
        // boot() has registered it, and boot() otherwise only runs from PromptTheme::activate(),
        // which ShowCommand never reaches and ChatCommand only reaches after its first prompt.
        // Idempotent and a no-op with no theme set, so unconditional here is free.
        self::boot();

        return self::theme() !== null
            ? 'paider-'.$role->value
            : self::DEFAULT_TW[$role->value];
    }

    /**
     * Raw SGR prefix for a role, e.g. "\e[36m". '' when disabled. Any 256-colour
     * entry (Brand, theme overrides) additionally returns '' unless TERM contains
     * '256color' or COLORTERM is non-empty — the glyph then renders in default fg.
     */
    public static function sgr(ColorRole $role): string
    {
        if (! self::enabled()) {
            return '';
        }

        $theme = self::theme();

        if ($theme !== null) {
            return self::supports256() ? "\e[".self::THEMES[$theme]['sgr'][$role->value].'m' : '';
        }

        if ($role === ColorRole::Brand) {
            return self::supports256() ? "\e[".self::DEFAULT_SGR['brand'].'m' : '';
        }

        return "\e[".self::DEFAULT_SGR[$role->value].'m';
    }

    /** "\e[0m" when enabled, '' when not — keeps hand-wrapping callers symmetric. */
    private static function reset(): string
    {
        return self::enabled() ? "\e[0m" : '';
    }

    /** sgr($role).$text.reset(); returns $text untouched when disabled. */
    public static function wrap(ColorRole $role, string $text): string
    {
        if (! self::enabled()) {
            return $text;
        }

        return self::sgr($role).$text.self::reset();
    }

    /**
     * Termwind\render() replacement — the only one call sites should use. Termwind's own
     * <table> renderer hard-codes `new BufferedOutput(..., decorated: true)` for its internal
     * Symfony Table helper (vendor/nunomaduro/termwind/src/Html/TableRenderer.php), so a
     * <table>'s <th>/<td> bold survives NO_COLOR and a piped stdout no matter what Termwind's
     * own top-level renderer decorates — confirmed by reading that file, not assumed. And
     * Laravel Zero installs Termwind's top-level renderer from its OWN isatty auto-detection
     * (Kernel::handle()), wired to nothing Palette controls, so PAIDER_COLOR=1 never reaches
     * Termwind surfaces at all. Rendering into our own buffer with an explicit decorated flag —
     * then stripping any SGR that still leaked through the table path — is the only fix
     * reachable without patching vendor. Writes into whatever renderer the caller already
     * installed (Laravel Zero's Kernel wires Termwind to the real console, or in a test, to a
     * BufferedOutput) rather than a bare echo(), so command output stays capturable — swap in
     * our own decorated-controlled buffer only for the duration of this call, then restore it.
     */
    public static function render(string $html): void
    {
        $realOutput = TermwindRenderer::getRenderer();

        $buffer = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, self::enabled());
        \Termwind\renderUsing($buffer);
        \Termwind\render($html);
        \Termwind\renderUsing($realOutput);

        $text = $buffer->fetch();

        if (! self::enabled()) {
            $text = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;
        }

        $realOutput->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Banner sweep, darkest -> brightest, as SGR param strings ('0;34', '0;94',
     * '0;37', '1;37' by default; themes may override). Returns [] when disabled —
     * Banner treats [] as its existing undecorated branch, replacing its own
     * stream_isatty() check. Banner's subtitle uses the second-brightest shade.
     *
     * @return list<string>
     */
    public static function gradient(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $theme = self::theme();

        return $theme !== null ? (self::THEMES[$theme]['gradient'] ?? self::DEFAULT_GRADIENT) : self::DEFAULT_GRADIENT;
    }

    /** Resolved theme name, or null when the user's own terminal colours rule. */
    public static function theme(): ?string
    {
        if (self::$themeResolved) {
            return self::$themeCache;
        }

        self::$themeResolved = true;
        $name = ProjectEnv::get('PAIDER_THEME');

        // A typo in .paider/.env must not break chat — unknown name falls back silently.
        return self::$themeCache = ($name !== null && isset(self::THEMES[$name])) ? $name : null;
    }

    /**
     * Registers the active theme's 'paider-<role>' Termwind styles (hex colours via
     * Termwind\style(), which Symfony degrades per terminal capability). Idempotent;
     * no-op when no theme is set. Called exactly once, from PromptTheme::activate().
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $name = self::theme();

        if ($name === null) {
            return;
        }

        self::$booted = true;
        $colors = self::THEMES[$name]['tw'];

        // Termwind resolves a hex only when it's registered as a named colour and referenced
        // via bg-<name>/text-<name> — a literal hex in a class string isn't supported, and a
        // dash inside the name breaks Termwind's own class-token parser, hence the dashless keys.
        // Brand is registered even though tw(Brand) has no caller today (it renders only
        // through wrap()/sgr(), the spinner): tw() promises every role resolves safely under
        // any theme, and leaving one role's style unregistered reintroduces the exact
        // StyleNotFound trap this method exists to close, for whichever call site reaches it first.
        foreach (['accent', 'muted', 'success', 'error', 'brand'] as $role) {
            $colorName = 'paider'.$role.'color';
            style($colorName)->color($colors[$role]);
            style('paider-'.$role)->apply('text-'.$colorName);
        }

        style('paideralertbgcolor')->color($colors['alert']['bg']);
        style('paideralertfgcolor')->color($colors['alert']['fg']);
        style('paider-alert')->apply('bg-paideralertbgcolor text-paideralertfgcolor');
    }

    /** Test seam — clears the cached gate and theme resolution. */
    public static function forget(): void
    {
        self::$enabledCache = null;
        self::$themeResolved = false;
        self::$themeCache = null;
        self::$booted = false;
    }

    private static function supports256(): bool
    {
        return str_contains((string) getenv('TERM'), '256color') || (string) getenv('COLORTERM') !== '';
    }
}

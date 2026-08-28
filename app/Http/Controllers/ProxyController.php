<?php

namespace App\Http\Controllers;

use App\Data\ProxyPermissions;
use App\Enums\AnalyticsWindow;
use App\Enums\ProxyMode;
use App\Enums\SecretPurpose;
use App\Http\Requests\StoreProxyRequest;
use App\Http\Requests\UpdateProxyRequest;
use App\Http\Resources\ProxyFormResource;
use App\Http\Resources\ProxyResource;
use App\Http\Resources\ProxySecurityResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\DeliveryStatistics;
use App\Services\IngestTokenService;
use App\Services\SecretStore;
use App\Support\SensitiveFields;
use App\Support\StandardWebhooks;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProxyController extends Controller
{
    public function __construct(
        private DeliveryStatistics $statistics,
        private SecretStore $secretStore,
    ) {}

    /**
     * Paginated list of the current team's proxies (AC4/AC12d).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Proxy::class);

        // Keep the native paginator envelope (data/links/last_page) the Index
        // page relies on, mapping each row through the resource. Destinations are
        // not eager-loaded here, so the resource omits them for the list.
        $proxies = Proxy::query()
            ->latest()
            ->paginate(15)
            ->through(fn (Proxy $proxy) => new ProxyResource($proxy));

        // Page-level proxy affordances for the acting user on the current team
        // (ADR-009 §4 tier 1, Amendment B4). Each row's edit/delete visibility is
        // composed client-side from these booleans + ProxyResource.is_creator — no
        // per-record policy call.
        return Inertia::render('proxies/Index', [
            'proxies' => $proxies,
            'permissions' => $this->proxyPermissions($request),
        ]);
    }

    /**
     * Show the create-proxy form (AC1).
     */
    public function create(): Response
    {
        $this->authorize('create', Proxy::class);

        // Single-sourced from SensitiveFields::DEFAULTS (T4), never a hand-typed
        // copy — create() renders no ProxyResource at all, so this and the
        // Standard Webhooks tolerance are page props on both create() and
        // edit() rather than resource keys (plan-10 Technical ruling 3).
        return Inertia::render('proxies/Create', [
            'defaultSensitiveFieldNames' => SensitiveFields::DEFAULTS,
            'standardWebhooksTolerance' => StandardWebhooks::TOLERANCE_SECONDS,
        ]);
    }

    /**
     * Persist a new proxy with its destinations in one transaction (AC1/AC2/AC3/AC12).
     */
    public function store(StoreProxyRequest $request, IngestTokenService $tokens): RedirectResponse
    {
        $this->authorize('create', Proxy::class);

        $data = $request->validated();

        $proxy = DB::transaction(function () use ($data, $tokens): Proxy {
            // Pass the validated payload straight to mass-assignment: only
            // name/mode/processing_mode/retry_*/response_* are fillable (see
            // Proxy #[Fillable]), so the `destinations` key is ignored and the
            // ingest token/hash stay server-minted, never from input. The two
            // retry keys are added to the array only when the submitted mode
            // is Enhanced (ADR-018 Decision 3, review-06 Minor 8(b)); on a
            // Simple submission the conditional yields [], so
            // array_merge($data, []) is just $data — and $data still carries
            // both retry keys as NULL (validation permits null under
            // prohibited_if, and the client normalises both to null on every
            // Simple submission). So a Simple-mode create still writes both
            // columns as NULL, same as an omission would produce, since
            // there is nothing yet to preserve on a create; this mirrors
            // `update()`'s omission in outcome, not in mechanism — `update()`
            // is where the omission actually matters, because it is the only
            // path that could otherwise clobber a proxy's existing preserved
            // values. `?? null` lets an omitted/absent value default to NULL
            // (AC2/AC20) when the mode IS Enhanced — Proxy::make() only
            // assigns keys present in $data, so this is set explicitly rather
            // than relying on mass-assignment omission.
            $proxy = Proxy::make(array_merge(
                $data,
                [
                    'sensitive_fields' => $this->sensitiveFieldAdditions($data),
                ],
                $data['mode'] === ProxyMode::Enhanced->value ? [
                    'retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
                    'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null,
                ] : [],
            ));
            $tokens->assignTo($proxy);
            $proxy->save();

            // Write-only (AC26): only a present, non-empty `verification_secret`
            // rotates the live secret through `SecretStore` (T14) — the single
            // writer of `proxy_secrets` (plan-10 Technical ruling 14). A create
            // has no prior secret, so this is always the first rotation when a
            // scheme was selected (T20's validation already requires it then).
            if (($data['verification_secret'] ?? null) !== null) {
                $this->secretStore->replace($proxy, SecretPurpose::Verification, $data['verification_secret']);
            }

            foreach ($this->destinationRows($data) as $destination) {
                $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $destination['url'],
                    'http_method' => $destination['http_method'],
                    ...$this->destinationCredentialAttributes($destination),
                ]);
            }

            // Guard the min-1 live invariant before commit (belt-and-suspenders to
            // the FormRequest's min:1).
            if ($proxy->destinations()->count() < 1) {
                throw ValidationException::withMessages([
                    'destinations' => __('A proxy must have at least one destination.'),
                ]);
            }

            return $proxy;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy created.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }

    /**
     * Show a single proxy with its ingest URL and destinations (AC4/AC12d).
     *
     * The leading `{current_team}` route parameter is accepted so implicit binding
     * of `{proxy}` aligns correctly under the team-prefixed group.
     */
    public function show(Request $request, string $current_team, Proxy $proxy): Response
    {
        $this->authorize('view', $proxy);

        // AC17/plan-11 Technical ruling 8: an unrecognised or absent `window`
        // resolves to the default rather than a 422 — never propagated further.
        // Carried from a Dashboard drill-through link (design-11 § Interactions)
        // when present, so the period survives the drill-down.
        $window = AnalyticsWindow::tryFrom((string) $request->query('window')) ?? AnalyticsWindow::default();

        // Share the page-level permission booleans alongside the resource so Show.vue
        // composes the edit/delete affordances client-side from these + is_creator
        // (ADR-009 Amendment B5) — server enforcement is unchanged.
        return Inertia::render('proxies/Show', [
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
            'permissions' => $this->proxyPermissions($request),
            'statistics' => $this->statistics->forProxy($proxy, $window),
            'destinations' => $this->statistics->destinationBreakdown($proxy, $window),
            // Status-only verification/signing/credential state (plan-10
            // Technical ruling 3) — a sibling prop, never a ProxyResource key.
            'security' => ProxySecurityResource::make($proxy),
        ]);
    }

    /**
     * Show the pre-filled edit form (live destinations only) (AC16a).
     */
    public function edit(string $current_team, Proxy $proxy): Response
    {
        $this->authorize('update', $proxy);

        // ProxyFormResource is the single Amendment-A carve-out: it emits the
        // raw retry columns regardless of mode, so the Edit form can pre-fill
        // a dormant policy. No other caller may use it (AC14(b)).
        return Inertia::render('proxies/Edit', [
            'proxy' => ProxyFormResource::make($proxy->loadMissing('destinations')),
            'defaultSensitiveFieldNames' => SensitiveFields::DEFAULTS,
            'standardWebhooksTolerance' => StandardWebhooks::TOLERANCE_SECONDS,
            // Same sibling prop as show() (plan-10 Technical ruling 3) — the
            // Verification section (T23) needs it for the write-only
            // set/unset/overlap states; create() renders no proxy resource
            // at all, so it never gets this prop.
            'security' => ProxySecurityResource::make($proxy),
        ]);
    }

    /**
     * Update name/mode and reconcile destinations in one transaction (AC16a/AC16b).
     *
     * Reconciliation: existing live rows are updated by id, new rows are created,
     * omitted rows are soft-deleted; ≥1 live destination must remain before commit.
     * Editing never rotates the ingest token.
     */
    public function update(UpdateProxyRequest $request, string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $data = $request->validated();

        DB::transaction(function () use ($data, $proxy): void {
            // Persist response/retry config alongside name/mode. `?? null` lets
            // an omitted/explicit-null field clear a previously configured
            // response value (AC3, AC20). The two retry keys are OMITTED from
            // the array entirely unless the submitted mode is Enhanced
            // (ADR-018 Decision 3, review-06 Minor 8(b)) — a Simple-mode save
            // never writes either retry column, not a value, not NULL, so a
            // proxy already holding a dormant policy keeps it verbatim
            // (PRD-07 AC14). An Enhanced-mode save writes exactly what
            // validation returned; `?? null` there still lets an explicit
            // NULL clear to the unconfigured system-default sentinel
            // (PRD-06 AC2). Preservation is achieved by not writing, not by a
            // read-before-write.
            $proxy->update([
                'name' => $data['name'],
                'mode' => $data['mode'],
                'processing_mode' => $data['processing_mode'],
                'response_status' => $data['response_status'] ?? null,
                'response_body' => $data['response_body'] ?? null,
                'sensitive_fields' => $this->sensitiveFieldAdditions($data),
                // Inbound verification (AC23, AC24, AC26; T20). An omitted/
                // explicit-null scheme means "not required" and, per plan-10
                // §Architecture B, deliberately does NOT clear a dormant
                // secret — `SecretStore::disable()` is never called for the
                // verification purpose from here (that method is reserved
                // for signing's different on/off semantics).
                'verification_scheme' => $data['verification_scheme'] ?? null,
                'verification_header_name' => $data['verification_header_name'] ?? null,
                ...($data['mode'] === ProxyMode::Enhanced->value ? [
                    'retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
                    'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null,
                ] : []),
            ]);

            // Write-only (AC26): only a present, non-empty `verification_secret`
            // rotates the live secret through `SecretStore` (T14) — absent
            // means "leave unchanged" (T20's validation already forbids an
            // empty string from reaching here via `min:8`).
            if (($data['verification_secret'] ?? null) !== null) {
                $this->secretStore->replace($proxy, SecretPurpose::Verification, $data['verification_secret']);
            }

            $keptIds = [];

            foreach ($this->destinationRows($data) as $row) {
                $existing = $row['id'] !== null
                    ? $proxy->destinations()->whereKey($row['id'])->first()
                    : null;

                if ($existing !== null) {
                    $existing->update([
                        'url' => $row['url'],
                        'http_method' => $row['http_method'],
                        ...$this->destinationCredentialAttributes($row),
                    ]);
                    $keptIds[] = $existing->id;

                    continue;
                }

                $created = $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $row['url'],
                    'http_method' => $row['http_method'],
                    ...$this->destinationCredentialAttributes($row),
                ]);
                $keptIds[] = $created->id;
            }

            // Soft-delete the live destinations that were omitted from the submission.
            $proxy->destinations()->whereNotIn('id', $keptIds)->get()
                ->each(fn (Destination $destination) => $destination->delete());

            if ($proxy->destinations()->count() < 1) {
                throw ValidationException::withMessages([
                    'destinations' => __('A proxy must have at least one destination.'),
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }

    /**
     * Soft-delete the proxy and its live destinations in one transaction (AC16d).
     *
     * delivery_attempts are always retained (never cascade-removed, no soft delete).
     */
    public function destroy(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('delete', $proxy);

        DB::transaction(function () use ($proxy): void {
            $proxy->destinations()->get()
                ->each(fn (Destination $destination) => $destination->delete());
            $proxy->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy deleted.')]);

        return to_route('proxies.index');
    }

    /**
     * Build the page-level proxy permission DTO for the acting user on their
     * current team (ADR-009 Amendment B4). A user without a current team gets an
     * all-false DTO — the fail-closed default.
     */
    private function proxyPermissions(Request $request): ProxyPermissions
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        if ($user === null || $team === null) {
            return new ProxyPermissions(
                canCreateProxy: false,
                canViewProxy: false,
                canUpdateProxy: false,
                canDeleteProxy: false,
                canUpdateAnyProxy: false,
                canDeleteAnyProxy: false,
                canReplayProxy: false,
            );
        }

        return $user->toProxyPermissions($team);
    }

    /**
     * Trim each submitted AC13 addition and de-duplicate by normalised form
     * (T4's `SensitiveFields::normalise()`) before persistence — the first
     * occurrence's original spelling is kept. The default list is never
     * stored per-proxy at all (it's code, not data), so this only ever
     * touches this proxy's own additions (AC13's per-proxy grain).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function sensitiveFieldAdditions(array $data): array
    {
        $submitted = $data['sensitive_fields'] ?? [];
        $seenNormalised = [];
        $additions = [];

        foreach (is_array($submitted) ? $submitted : [] as $name) {
            if (! is_string($name)) {
                continue;
            }

            $trimmed = trim($name);

            if ($trimmed === '') {
                continue;
            }

            $normalised = SensitiveFields::normalise($trimmed);

            if (isset($seenNormalised[$normalised])) {
                continue;
            }

            $seenNormalised[$normalised] = true;
            $additions[] = $trimmed;
        }

        return $additions;
    }

    /**
     * Normalise the validated destinations payload into typed rows.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id: int|null, url: string, http_method: string, credential_header_name: string, credential_secret: string, remove_credential: bool}>
     */
    private function destinationRows(array $data): array
    {
        $rows = $data['destinations'] ?? [];
        $normalised = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = is_array($row) ? $row : [];

            $normalised[] = [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
                'url' => isset($row['url']) && is_string($row['url']) ? $row['url'] : '',
                'http_method' => isset($row['http_method']) && is_string($row['http_method']) ? $row['http_method'] : '',
                // Write-only (AC33, T29): present-but-empty is normalised here
                // to the same '' the "leave unchanged"/"nothing configured"
                // branch of destinationCredentialAttributes() checks for —
                // isset() is deliberately used (not array_key_exists()), so a
                // submitted explicit null also normalises to ''.
                'credential_header_name' => isset($row['credential_header_name']) && is_string($row['credential_header_name']) ? $row['credential_header_name'] : '',
                'credential_secret' => isset($row['credential_secret']) && is_string($row['credential_secret']) ? $row['credential_secret'] : '',
                // The Remove credential signal (T31; ruling 15) — read
                // positively, so presence-versus-absence of the key is never
                // load-bearing (isset() would be false for an explicit null,
                // which is exactly the hazard ruling 15 exists to avoid on
                // this key's own design; reading positively against `?? false`
                // sidesteps it entirely).
                'remove_credential' => ($row['remove_credential'] ?? false) === true,
            ];
        }

        return $normalised;
    }

    /**
     * The mass-assignable credential attributes for one destination row
     * (AC30, AC33; T29) — `[]` (a no-op, preserving whatever is already
     * stored) whenever no non-empty `credential_secret` was submitted for
     * this row, matching binding constraint 8: a present-but-empty secret
     * field never clears a stored secret. A non-empty secret always sets
     * `credential_set_at` to the moment of this save, and defaults the
     * header name to `Authorization` only as a defensive fallback — the
     * form itself always supplies a header name once a secret is present
     * (T29's own `required_with` validation rule).
     *
     * @param  array{credential_header_name: string, credential_secret: string, remove_credential: bool}  $row
     * @return array{credential_header_name?: string|null, credential_secret?: string|null, credential_set_at?: CarbonImmutable|null}
     */
    private function destinationCredentialAttributes(array $row): array
    {
        // T31 (ruling 15) — checked first: validation's `prohibited_if`
        // already guarantees `credential_secret` is empty whenever this flag
        // is true, so there is no ordering ambiguity between the two
        // branches below. All three columns are nulled together, so a row
        // can never come to rest holding a header name with no secret — the
        // result is byte-identical to a destination that never had one.
        if ($row['remove_credential']) {
            return [
                'credential_header_name' => null,
                'credential_secret' => null,
                'credential_set_at' => null,
            ];
        }

        if ($row['credential_secret'] === '') {
            return [];
        }

        return [
            'credential_header_name' => $row['credential_header_name'] !== '' ? $row['credential_header_name'] : 'Authorization',
            'credential_secret' => $row['credential_secret'],
            'credential_set_at' => now(),
        ];
    }
}

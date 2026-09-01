<?php

namespace App\Http\Controllers;

use App\Actions\SendDestinationValidationChallenge;
use App\Data\ProxyPermissions;
use App\Enums\AnalyticsWindow;
use App\Enums\DestinationValidationState;
use App\Enums\ProxyMode;
use App\Http\Requests\StoreProxyRequest;
use App\Http\Requests\UpdateProxyRequest;
use App\Http\Resources\ProxyFormResource;
use App\Http\Resources\ProxyResource;
use App\Http\Resources\ProxySecurityResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\DeliveryStatistics;
use App\Services\IngestTokenService;
use App\Support\SensitiveFields;
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
        // copy — create() renders no ProxyResource at all, so this is a page
        // prop on both create() and edit() rather than a resource key
        // (plan-10 Technical ruling 3).
        return Inertia::render('proxies/Create', [
            'defaultSensitiveFieldNames' => SensitiveFields::DEFAULTS,
        ]);
    }

    /**
     * Persist a new proxy with its destinations in one transaction (AC1/AC2/AC3/AC12).
     */
    public function store(StoreProxyRequest $request, IngestTokenService $tokens): RedirectResponse
    {
        $this->authorize('create', Proxy::class);

        $data = $request->validated();

        // Item #18 AC15: a new destination is challenged automatically. Ids are
        // collected inside the transaction and dispatched after it commits, so a
        // rolled-back create never sends a challenge for a destination that does
        // not exist.
        $toChallenge = [];

        $proxy = DB::transaction(function () use ($data, $tokens, &$toChallenge): Proxy {
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

            foreach ($this->destinationRows($data) as $destination) {
                $toChallenge[] = $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $destination['url'],
                    'http_method' => $destination['http_method'],
                    ...$this->destinationCredentialAttributes($destination),
                ])->id;
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

        $this->challengeDestinations($toChallenge);

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
            // Status-only signing/credential state (plan-10 Technical ruling
            // 3) — a sibling prop, never a ProxyResource key.
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
            // Same sibling prop as show() (plan-10 Technical ruling 3) —
            // create() renders no proxy resource at all, so it never gets
            // this prop.
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

        // Item #18 AC5/AC15: a new destination, or one whose URL changed, is
        // challenged after the transaction commits.
        $toChallenge = [];

        DB::transaction(function () use ($data, $proxy, &$toChallenge): void {
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
                ...($data['mode'] === ProxyMode::Enhanced->value ? [
                    'retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
                    'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null,
                ] : []),
            ]);

            $keptIds = [];

            foreach ($this->destinationRows($data) as $row) {
                $existing = $row['id'] !== null
                    ? $proxy->destinations()->whereKey($row['id'])->first()
                    : null;

                if ($existing !== null) {
                    // Item #18 AC5: changing the URL returns the destination to
                    // unvalidated and voids the outstanding link. Editing is
                    // deliberately NOT blocked — blocking would only push
                    // members to delete and recreate, which is the same work
                    // with a worse audit trail. Anything other than the URL
                    // (method, credential) leaves validation alone, because
                    // configuration is not gated (AC13).
                    $urlChanged = $existing->url !== $row['url'];

                    $existing->update([
                        'url' => $row['url'],
                        'http_method' => $row['http_method'],
                        ...$this->destinationCredentialAttributes($row, $existing->credential_set_at !== null),
                    ]);

                    // forceFill rather than update: the validation columns are
                    // deliberately absent from the model's #[Fillable] list, so
                    // that no request payload can ever mass-assign a
                    // destination into the validated state. Only this reset and
                    // the approval route write them.
                    if ($urlChanged) {
                        $existing->forceFill([
                            'validation_state' => DestinationValidationState::Unvalidated,
                            'validated_at' => null,
                            'validation_challenge_sent_at' => null,
                            'validation_challenge_expires_at' => null,
                            'validation_nonce' => null,
                            // AC35's outcome columns clear with the rest: they
                            // describe a send to the old address and would
                            // misdescribe the new one.
                            'validation_last_send_status' => null,
                            'validation_last_send_failure' => null,
                        ])->save();
                    }

                    $keptIds[] = $existing->id;

                    if ($urlChanged) {
                        $toChallenge[] = $existing->id;
                    }

                    continue;
                }

                $created = $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $row['url'],
                    'http_method' => $row['http_method'],
                    ...$this->destinationCredentialAttributes($row),
                ]);
                $keptIds[] = $created->id;
                $toChallenge[] = $created->id;
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

        $this->challengeDestinations($toChallenge);

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
     * (AC30, AC33; T29). A non-empty `credential_secret` always replaces the
     * secret, sets `credential_set_at` to the moment of this save, and
     * writes the header name alongside it (defaulting to `Authorization`
     * only as a defensive fallback — T29's own `required_with` validation
     * rule normally guarantees a header name accompanies a secret).
     *
     * A blank `credential_secret` never touches the stored secret (binding
     * constraint 8) — but design-10 Screen 3 keeps the header name field
     * visible and editable even once a credential is set (its per-row
     * states table's "Header name (editable)" row), so a changed name has
     * to persist on its own. `$hasExistingCredential` (review-10 Finding 4)
     * is how this method tells that case apart from a destination that has
     * never had a credential: only when one is already stored does a
     * blank-secret row write `credential_header_name` alone, leaving
     * `credential_secret` and `credential_set_at` untouched — a
     * header-name-only edit does not count as (re)setting the credential,
     * so the Show page's "Credential set — changed {date}" line keeps
     * reporting when the *secret* last changed, never the header name. A
     * destination with no stored credential yet always gets `[]` for a
     * blank secret regardless of header name, so a row can never come to
     * rest holding a header name with no secret.
     *
     * @param  array{credential_header_name: string, credential_secret: string, remove_credential: bool}  $row
     * @param  bool  $hasExistingCredential  whether this destination already has a credential
     *                                       stored (review-10 Finding 4) — true only for an
     *                                       `update()` row matched to an existing `Destination`
     *                                       whose `credential_set_at` is not null.
     * @return array{credential_header_name?: string|null, credential_secret?: string|null, credential_set_at?: CarbonImmutable|null}
     */
    private function destinationCredentialAttributes(array $row, bool $hasExistingCredential = false): array
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
            if ($hasExistingCredential && $row['credential_header_name'] !== '') {
                return ['credential_header_name' => $row['credential_header_name']];
            }

            return [];
        }

        return [
            'credential_header_name' => $row['credential_header_name'] !== '' ? $row['credential_header_name'] : 'Authorization',
            'credential_secret' => $row['credential_secret'],
            'credential_set_at' => now(),
        ];
    }

    /**
     * Queue a validation challenge for each destination id (#18 AC15).
     *
     * Dispatched rather than sent inline: a challenge is an outbound HTTP
     * request to a host that has not yet proven it wants traffic, and a member
     * saving a form should not wait on it — nor should a slow or hanging
     * destination hold the request open.
     *
     * A rate-limited send is deliberately not an error here. Product Manager
     * ruling 1: the destination still saves. The member sees its state on the
     * proxy page and can send a challenge with the Validate action when the
     * limit clears.
     *
     * @param  list<int>  $destinationIds
     */
    private function challengeDestinations(array $destinationIds): void
    {
        foreach ($destinationIds as $id) {
            SendDestinationValidationChallenge::dispatch(
                Destination::query()->whereKey($id)->firstOrFail()
            );
        }
    }
}

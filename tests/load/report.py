#!/usr/bin/env python3
"""Render history.ndjson as a comparison page.

Reads the committed run history and writes a self-contained HTML page comparing
each scenario before and after a change, keyed on the git SHA of the run.

Usage: report.py <after-sha> [--out FILE]

Baseline is each scenario's earliest run; the "after" sha selects the comparison.
"""

import argparse
import html
import json
from pathlib import Path

HERE = Path(__file__).parent

# Categorical slots 1 and 2, validated for both surfaces.
LIGHT = {"a": "#2a78d6", "b": "#eb6834"}
DARK = {"a": "#3987e5", "b": "#d95926"}


def load(history: Path) -> list[dict]:
    return [json.loads(line) for line in history.read_text().splitlines() if line.strip()]


def bar_row(label: str, before: float, after: float, worst: float, unit: str = "") -> str:
    """One scenario: two bars sharing a scale, each directly labelled."""
    pb = (before / worst * 100) if worst else 0
    pa = (after / worst * 100) if worst else 0
    delta = ((after - before) / before * 100) if before else 0
    sign = "&minus;" if delta < 0 else "+"
    return f"""
    <div class="row">
      <div class="row-head">
        <span class="row-label">{html.escape(label)}</span>
        <span class="row-delta {'down' if delta < 0 else 'up'}">{sign}{abs(delta):.1f}%</span>
      </div>
      <div class="bars">
        <div class="bar-line" title="before: {before:g}{unit}">
          <div class="bar a" style="width:{pb:.2f}%"></div>
          <span class="bar-value">{before:g}</span>
        </div>
        <div class="bar-line" title="after: {after:g}{unit}">
          <div class="bar b" style="width:{pa:.2f}%"></div>
          <span class="bar-value">{after:g}</span>
        </div>
      </div>
    </div>"""


def step_row(label: str, value: float, worst: float, note: str, slot: str) -> str:
    pct = (value / worst * 100) if worst else 0
    return f"""
    <div class="row">
      <div class="row-head">
        <span class="row-label">{html.escape(label)}</span>
        <span class="row-note">{html.escape(note)}</span>
      </div>
      <div class="bars">
        <div class="bar-line" title="{value:g} selects per ingest">
          <div class="bar {slot}" style="width:{pct:.2f}%"></div>
          <span class="bar-value">{value:g}</span>
        </div>
      </div>
    </div>"""


def control_row(label: str, before: float, after: float) -> str:
    """A control variable: shown as numbers, not bars.

    Drain rates span two orders of magnitude across scenarios, so one shared bar
    scale renders the slow scenarios as slivers while a per-row scale would imply
    a cross-row comparison the data cannot support. Numbers carry it honestly.
    """
    delta = ((after - before) / before * 100) if before else 0
    sign = "&minus;" if delta < 0 else "+"
    return f"""
    <div class="ctrl">
      <span class="ctrl-label">{html.escape(label)}</span>
      <span class="ctrl-val">{before:g} &rarr; {after:g}/s</span>
      <span class="ctrl-delta">{sign}{abs(delta):.1f}%</span>
    </div>"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("after", help="git sha of the runs to compare against baseline")
    parser.add_argument("--history", default=str(HERE / "history.ndjson"))
    parser.add_argument("--out", default=str(HERE / "report.html"))
    args = parser.parse_args()

    rows = load(Path(args.history))

    after_runs = {r["scenario"]: r for r in rows if r["sha"].startswith(args.after)}

    # Baseline is each scenario's earliest recorded run. The five baselines were
    # not taken at one commit — they were captured as each scenario was first
    # validated — so keying them off a single sha would silently drop scenarios.
    before_runs: dict[str, dict] = {}
    for r in rows:
        if r["sha"].startswith(args.after):
            continue
        before_runs.setdefault(r["scenario"], r)
    scenarios = [s for s in before_runs if s in after_runs]

    # Every async-throughput run in order: the progression that shows the harness
    # reporting no change for a cache that did nothing.
    progression = [r for r in rows if r["scenario"] == "async-throughput"]

    sel_worst = max(
        [before_runs[s]["selects_per_ingest"] for s in scenarios]
        + [after_runs[s]["selects_per_ingest"] for s in scenarios]
        + [r["selects_per_ingest"] for r in progression],
        default=1,
    )
    drain_worst = max(
        [before_runs[s]["drain_rate_per_s"] for s in scenarios]
        + [after_runs[s]["drain_rate_per_s"] for s in scenarios],
        default=1,
    )

    # Combined reads per event, not summed totals. A run's total depends on how
    # many events it happened to process, so summing across scenarios weights the
    # aggregate by run size and reports a number that is not about caching at all.
    total_before = sum(before_runs[s]["queries"]["select"] for s in scenarios)
    total_after = sum(after_runs[s]["queries"]["select"] for s in scenarios)
    events_before = sum(before_runs[s]["ingested"] for s in scenarios)
    events_after = sum(after_runs[s]["ingested"] for s in scenarios)
    per_before = total_before / events_before if events_before else 0
    per_after = total_after / events_after if events_after else 0
    headline = ((per_after - per_before) / per_before * 100) if per_before else 0
    total_events = events_before + events_after

    sel_rows = "".join(
        bar_row(s, before_runs[s]["selects_per_ingest"], after_runs[s]["selects_per_ingest"], sel_worst)
        for s in scenarios
    )
    drain_rows = "".join(
        control_row(s, before_runs[s]["drain_rate_per_s"], after_runs[s]["drain_rate_per_s"])
        for s in scenarios
    )

    notes = ["baseline", "cache present but inert", "proxy token lookup", "delivery rows too"]
    prog_rows = "".join(
        step_row(
            r["sha"],
            r["selects_per_ingest"],
            sel_worst,
            notes[i] if i < len(notes) else "",
            "a" if i < 2 else "b",
        )
        for i, r in enumerate(progression)
    )

    table_rows = "".join(
        f"<tr><td>{html.escape(s)}</td>"
        f"<td>{before_runs[s]['selects_per_ingest']:g}</td>"
        f"<td>{after_runs[s]['selects_per_ingest']:g}</td>"
        f"<td>{before_runs[s]['queries']['select']}</td>"
        f"<td>{after_runs[s]['queries']['select']}</td>"
        f"<td>{before_runs[s]['drain_rate_per_s']:g}</td>"
        f"<td>{after_runs[s]['drain_rate_per_s']:g}</td>"
        f"<td>{html.escape(str(after_runs[s]['fifo_order']))}</td></tr>"
        for s in scenarios
    )

    page = TEMPLATE.format(
        total_events=total_events,
        per_before=f"{per_before:.1f}",
        per_after=f"{per_after:.1f}",
        headline=f"{'&minus;' if headline < 0 else '+'}{abs(headline):.1f}%",
        total_before=total_before,
        total_after=total_after,
        baseline_sha=html.escape(", ".join(sorted({before_runs[s]["sha"] for s in scenarios}))),
        after_sha=html.escape(args.after),
        sel_rows=sel_rows,
        drain_rows=drain_rows,
        prog_rows=prog_rows,
        table_rows=table_rows,
        scenario_count=len(scenarios),
        **{f"l_{k}": v for k, v in LIGHT.items()},
        **{f"d_{k}": v for k, v in DARK.items()},
    )

    Path(args.out).write_text(page)
    print(f"wrote {args.out} ({len(scenarios)} scenarios)")

    return 0


TEMPLATE = """<title>Ingest Caching Impact</title>
<style>
  .viz-root {{
    color-scheme: light;
    --surface-1: #fcfcfb;
    --surface-2: #f2f2f0;
    --text-primary: #0b0b0b;
    --text-secondary: #52514e;
    --text-muted: #78766f;
    --rule: #dedcd6;
    --series-a: {l_a};
    --series-b: {l_b};
  }}
  @media (prefers-color-scheme: dark) {{
    :root:where(:not([data-theme="light"])) .viz-root {{
      color-scheme: dark;
      --surface-1: #1a1a19;
      --surface-2: #232322;
      --text-primary: #ffffff;
      --text-secondary: #c3c2b7;
      --text-muted: #96948a;
      --rule: #34342f;
      --series-a: {d_a};
      --series-b: {d_b};
    }}
  }}
  :root[data-theme="dark"] .viz-root {{
    color-scheme: dark;
    --surface-1: #1a1a19;
    --surface-2: #232322;
    --text-primary: #ffffff;
    --text-secondary: #c3c2b7;
    --text-muted: #96948a;
    --rule: #34342f;
    --series-a: {d_a};
    --series-b: {d_b};
  }}
  body {{ margin: 0; background: var(--surface-1); }}
  .viz-root {{
    background: var(--surface-1);
    color: var(--text-primary);
    font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    padding: 40px 24px 72px;
    max-width: 860px;
    margin: 0 auto;
  }}
  h1 {{ font-size: 27px; margin: 0 0 6px; letter-spacing: -0.02em; }}
  h2 {{ font-size: 17px; margin: 40px 0 4px; letter-spacing: -0.01em; }}
  .sub {{ color: var(--text-secondary); margin: 0 0 4px; }}
  .meta {{ color: var(--text-muted); font-size: 13px; margin: 0; }}
  code {{ font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.92em; }}

  .hero {{
    display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap;
    margin: 28px 0 8px; padding: 20px 22px;
    background: var(--surface-2); border-radius: 10px;
  }}
  .hero-num {{ font-size: 46px; font-weight: 600; letter-spacing: -0.03em; line-height: 1; }}
  .hero-label {{ color: var(--text-secondary); font-size: 14px; }}

  .legend {{ display: flex; gap: 18px; margin: 14px 0 18px; font-size: 13px; color: var(--text-secondary); }}
  .key {{ display: inline-flex; align-items: center; gap: 7px; }}
  .swatch {{ width: 11px; height: 11px; border-radius: 3px; flex: none; }}
  .swatch.a {{ background: var(--series-a); }}
  .swatch.b {{ background: var(--series-b); }}

  .row {{ padding: 11px 0; border-bottom: 1px solid var(--rule); }}
  .row:last-child {{ border-bottom: 0; }}
  .row-head {{ display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 7px; }}
  .row-label {{ font-size: 13.5px; color: var(--text-primary); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }}
  .row-delta {{ font-size: 13px; font-variant-numeric: tabular-nums; color: var(--text-secondary); }}
  .row-delta.down {{ color: var(--text-primary); font-weight: 600; }}
  .row-note {{ font-size: 12.5px; color: var(--text-muted); }}
  .bars {{ display: flex; flex-direction: column; gap: 2px; }}
  .bar-line {{ display: flex; align-items: center; gap: 9px; }}
  .bar {{ height: 13px; border-radius: 0 4px 4px 0; min-width: 2px; transition: opacity .12s; }}
  .bar.a {{ background: var(--series-a); }}
  .bar.b {{ background: var(--series-b); }}
  .bar-line:hover .bar {{ opacity: .78; }}
  .bar-value {{
    font-size: 12.5px; color: var(--text-secondary);
    font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }}

  .tablewrap {{ overflow-x: auto; margin-top: 12px; }}
  table {{ border-collapse: collapse; font-size: 13px; width: 100%; min-width: 620px; }}
  th, td {{ text-align: right; padding: 7px 10px; border-bottom: 1px solid var(--rule); white-space: nowrap; }}
  th:first-child, td:first-child {{ text-align: left; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }}
  th {{ color: var(--text-muted); font-weight: 500; font-size: 12px; }}
  td {{ font-variant-numeric: tabular-nums; color: var(--text-secondary); }}

  .ctrl {{
    display: flex; align-items: baseline; gap: 12px;
    padding: 9px 0; border-bottom: 1px solid var(--rule); font-size: 13.5px;
  }}
  .ctrl:last-of-type {{ border-bottom: 0; }}
  .ctrl-label {{ flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }}
  .ctrl-val {{ color: var(--text-secondary); font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }}
  .ctrl-delta {{ color: var(--text-muted); font-variant-numeric: tabular-nums; min-width: 58px; text-align: right; }}
  .note {{ background: var(--surface-2); border-radius: 8px; padding: 15px 18px; margin-top: 16px; color: var(--text-secondary); font-size: 14px; }}
  .note strong {{ color: var(--text-primary); }}
</style>

<div class="viz-root">
  <h1>Ingest caching impact</h1>
  <p class="sub">Database reads per webhook event, measured by the load harness across {scenario_count} scenarios.</p>
  <p class="meta">Baseline <code>{baseline_sha}</code> &rarr; cached <code>{after_sha}</code>. Same machine, same worker counts, same sink delays.</p>

  <div class="hero">
    <span class="hero-num">{headline}</span>
    <span class="hero-label">fewer database reads per event, across all scenarios<br>{per_before} &rarr; {per_after} reads per event, over {total_events:,} events</span>
  </div>

  <h2>Reads per event, by scenario</h2>
  <p class="meta">Lower is better. A count, not a timing &mdash; so a change here is real, not machine noise.</p>
  <div class="legend">
    <span class="key"><span class="swatch a"></span>Before caching</span>
    <span class="key"><span class="swatch b"></span>After caching</span>
  </div>
  {sel_rows}

  <h2>How the harness saw each step</h2>
  <p class="meta">The <code>async-throughput</code> scenario across every run. The second bar is a cache that was present but inert &mdash; the harness correctly reported no change.</p>
  {prog_rows}

  <h2>Delivery throughput, by scenario</h2>
  <p class="meta">Deliveries completed per second. Expected to be flat: caching removes reads, and delivery was never read-bound.</p>
  {drain_rows}

  <h2>All figures</h2>
  <div class="tablewrap">
    <table>
      <thead><tr>
        <th>Scenario</th><th>Reads/event before</th><th>after</th>
        <th>Total selects before</th><th>after</th>
        <th>Drain/s before</th><th>after</th><th>FIFO order</th>
      </tr></thead>
      <tbody>{table_rows}</tbody>
    </table>
  </div>

  <div class="note">
    <strong>What these numbers are.</strong> Runs on one developer machine under Docker, comparable
    to each other and not to a production host. Reads per event is deterministic and trustworthy at
    any size; latency and throughput on this setup carry roughly &plusmn;15% of machine noise, so treat
    small movements there as nothing.
  </div>
</div>
"""

if __name__ == "__main__":
    raise SystemExit(main())

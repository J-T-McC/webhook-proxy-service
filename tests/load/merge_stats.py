#!/usr/bin/env python3
"""Merge the arrival stats of every sink a scenario started.

The head-of-line scenario runs a second, deliberately slow sink and moves one
proxy's destinations onto it. Reading only the first sink would drop that proxy
from the delivered count, from the drain check and from the ordering verdict —
and the run would look clean precisely because the proxy it exists to observe
was invisible.

Usage: merge_stats.py <url> [<url> ...]
"""

import json
import sys
import urllib.error
import urllib.request


def main() -> int:
    total = 0
    proxies: dict[str, dict[str, int]] = {}

    for url in sys.argv[1:]:
        try:
            with urllib.request.urlopen(url, timeout=5) as response:
                stats = json.load(response)
        except (urllib.error.URLError, json.JSONDecodeError, OSError):
            # A sink that is not up yet, or already gone, contributes nothing.
            # Failing the whole run over it would be worse than under-counting
            # on one poll of the drain loop, which simply polls again.
            continue

        total += stats.get("total", 0)

        for name, value in stats.get("proxies", {}).items():
            seen = proxies.setdefault(name, {"received": 0, "violations": 0})
            seen["received"] += value.get("received", 0)
            seen["violations"] += value.get("violations", 0)

    print(json.dumps({"total": total, "proxies": proxies}))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

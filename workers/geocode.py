#!/usr/bin/env python3
"""Resolve queued eCRM address jobs without writing CRM records directly."""

from __future__ import annotations

import json
import os
import pathlib
import shutil
import sys
import time
import urllib.parse
import urllib.request


ROOT = pathlib.Path(os.getenv("ECRM_PRIVATE_ROOT") or pathlib.Path(__file__).resolve().parent.parent)
JOBS = ROOT / "jobs"
PENDING = JOBS / "pending"
PROCESSING = JOBS / "processing"
RESULTS = JOBS / "results"
FAILED = JOBS / "failed"


def ensure_dirs() -> None:
    for path in (PENDING, PROCESSING, RESULTS, FAILED):
        path.mkdir(parents=True, exist_ok=True)


def atomic_json(path: pathlib.Path, payload: dict) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    os.replace(tmp, path)


def provider_lookup(query: str) -> dict:
    endpoint = os.getenv("ECRM_GEOCODER_ENDPOINT", "").strip()
    if not endpoint:
        raise RuntimeError("ECRM_GEOCODER_ENDPOINT is not configured")

    params = {"q": query, "format": "jsonv2", "limit": "1"}
    token = os.getenv("ECRM_GEOCODER_KEY", "").strip()
    if token:
        params["key"] = token

    url = endpoint + ("&" if "?" in endpoint else "?") + urllib.parse.urlencode(params)
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": os.getenv("ECRM_GEOCODER_USER_AGENT", "eCRM-geocoder/0.2"),
            "Accept": "application/json",
        },
    )

    with urllib.request.urlopen(request, timeout=15) as response:
        data = json.loads(response.read().decode("utf-8"))

    if not isinstance(data, list) or not data:
        raise RuntimeError("No geocoding result")

    first = data[0]
    return {
        "latitude": float(first["lat"]),
        "longitude": float(first["lon"]),
        "confidence": float(first.get("importance", 0.0)),
        "display_name": str(first.get("display_name", "")),
    }


def process(path: pathlib.Path) -> bool:
    processing = PROCESSING / path.name
    os.replace(path, processing)
    try:
        job = json.loads(processing.read_text(encoding="utf-8"))
        result = provider_lookup(str(job["query"]))
        atomic_json(
            RESULTS / path.name,
            {
                "type": "geocode_result",
                "address_id": job["address_id"],
                "customer_id": job["customer_id"],
                "geocode_token": job.get("geocode_token"),
                "provider": os.getenv("ECRM_GEOCODER_ENDPOINT", ""),
                "resolved_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                **result,
            },
        )
        processing.unlink(missing_ok=True)
        return True
    except Exception as exc:
        try:
            job = json.loads(processing.read_text(encoding="utf-8"))
        except Exception:
            job = {"address_id": processing.stem}
        job["failed_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        job["error"] = str(exc)
        atomic_json(FAILED / path.name, job)
        processing.unlink(missing_ok=True)
        return False


def main() -> int:
    ensure_dirs()
    if not os.getenv("ECRM_GEOCODER_ENDPOINT", "").strip():
        print("Geocoding provider is not configured; pending jobs were left untouched")
        return 0
    limit = max(1, min(int(os.getenv("ECRM_GEOCODER_BATCH", "20")), 100))
    delay = max(0.0, float(os.getenv("ECRM_GEOCODER_DELAY_SECONDS", "1.0")))
    jobs = sorted(PENDING.glob("*.json"))[:limit]

    if not jobs:
        print("No pending geocoding jobs")
        return 0

    success = 0
    for index, path in enumerate(jobs):
        if process(path):
            success += 1
        if index + 1 < len(jobs) and delay:
            time.sleep(delay)

    print(f"Geocoding provider stage complete: {success}/{len(jobs)} resolved")
    return 0 if success == len(jobs) else 2


if __name__ == "__main__":
    sys.exit(main())

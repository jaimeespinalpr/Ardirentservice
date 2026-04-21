#!/usr/bin/env python3
import json
import os
import random
import re
import time
import urllib.error
import urllib.request
from pathlib import Path


USERNAME = os.environ.get("IG_USERNAME", "ardirentservice").strip()
LIMIT = int(os.environ.get("IG_LIMIT", "10"))

ROOT = Path(__file__).resolve().parents[1]
OUT_JSON = ROOT / "data" / "instagram_latest.json"
OUT_DIR = ROOT / "assets" / "instagram"
MAX_RETRIES = int(os.environ.get("IG_MAX_RETRIES", "5"))
BASE_BACKOFF_SECONDS = float(os.environ.get("IG_RETRY_BASE_SECONDS", "1.5"))


def _should_retry(exc: Exception) -> bool:
    if isinstance(exc, urllib.error.HTTPError):
        return exc.code == 429 or 500 <= exc.code < 600
    if isinstance(exc, urllib.error.URLError):
        return True
    if isinstance(exc, TimeoutError):
        return True
    return False


def _urlopen_with_retries(req: urllib.request.Request, timeout: int = 30):
    last_exc = None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            return urllib.request.urlopen(req, timeout=timeout)
        except Exception as exc:  # noqa: BLE001
            last_exc = exc
            if not _should_retry(exc) or attempt == MAX_RETRIES:
                break
            sleep_s = BASE_BACKOFF_SECONDS * (2 ** (attempt - 1)) + random.uniform(0, 0.75)
            print(
                f"[instagram-cache] transient error ({type(exc).__name__}) on attempt "
                f"{attempt}/{MAX_RETRIES}; retrying in {sleep_s:.1f}s"
            )
            time.sleep(sleep_s)
    raise last_exc  # type: ignore[misc]


def fetch_profile_json(username: str) -> dict:
    url = f"https://www.instagram.com/api/v1/users/web_profile_info/?username={username}"
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)",
            # Public endpoint used by Instagram web. This header is commonly required.
            "x-ig-app-id": "936619743392459",
        },
    )
    with _urlopen_with_retries(req, timeout=30) as resp:
        return json.load(resp)


def sanitize_caption(text: str) -> str:
    text = (text or "").strip()
    text = re.sub(r"\s+", " ", text)
    return text


def guess_ext(content_type: str) -> str:
    content_type = (content_type or "").lower().split(";")[0].strip()
    if content_type == "image/webp":
        return ".webp"
    if content_type == "image/png":
        return ".png"
    return ".jpg"


def download_image(url: str, dest_base: Path) -> str:
    req = urllib.request.Request(
        url,
        headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"},
    )
    with _urlopen_with_retries(req, timeout=30) as resp:
        content_type = resp.headers.get("Content-Type", "")
        data = resp.read()
    ext = guess_ext(content_type)
    dest = dest_base.with_suffix(ext)
    dest.write_bytes(data)
    return dest.name


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (ROOT / "data").mkdir(parents=True, exist_ok=True)

    try:
        payload = fetch_profile_json(USERNAME)
    except Exception as exc:  # noqa: BLE001
        if OUT_JSON.exists():
            print(
                "[instagram-cache] could not refresh feed; keeping existing cache "
                f"({type(exc).__name__}: {exc})"
            )
            return 0
        raise
    user = payload.get("data", {}).get("user", {})
    edges = (
        user.get("edge_owner_to_timeline_media", {})
        .get("edges", [])
    )

    posts = []
    for edge in edges[: LIMIT * 2]:
        node = edge.get("node", {})
        shortcode = node.get("shortcode")
        if not shortcode:
            continue
        caption_edges = node.get("edge_media_to_caption", {}).get("edges", [])
        caption = caption_edges[0]["node"].get("text", "") if caption_edges else ""
        display_url = node.get("display_url") or node.get("thumbnail_src") or ""
        taken = node.get("taken_at_timestamp") or 0
        if not display_url:
            continue
        posts.append(
            {
                "shortcode": shortcode,
                "url": f"https://www.instagram.com/p/{shortcode}/",
                "caption": sanitize_caption(caption),
                "timestamp": int(taken) if taken else 0,
                "image_url": display_url,
            }
        )
        if len(posts) >= LIMIT:
            break

    if not posts:
        if OUT_JSON.exists():
            print("[instagram-cache] no posts in response; keeping existing cache")
            return 0
        raise SystemExit("No posts found. Instagram response may have changed.")

    # Download images (keep them stable and fast for GitHub Pages).
    written = []
    for post in posts:
        base = OUT_DIR / post["shortcode"]
        filename = download_image(post["image_url"], base)
        written.append(filename)
        post["image"] = f"assets/instagram/{filename}"
        post.pop("image_url", None)

    # Remove old images not in the latest set.
    keep = set(written)
    for path in OUT_DIR.iterdir():
        if not path.is_file():
            continue
        if path.name not in keep:
            try:
                path.unlink()
            except OSError:
                pass

    OUT_JSON.write_text(
        json.dumps(
            {
                "username": USERNAME,
                "updated_at": int(time.time()),
                "posts": posts,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

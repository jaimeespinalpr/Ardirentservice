#!/usr/bin/env python3
import json
import os
import re
import time
import urllib.request
from pathlib import Path


USERNAME = os.environ.get("IG_USERNAME", "ardirentservice").strip()
LIMIT = int(os.environ.get("IG_LIMIT", "10"))

ROOT = Path(__file__).resolve().parents[1]
OUT_JSON = ROOT / "data" / "instagram_latest.json"
OUT_DIR = ROOT / "assets" / "instagram"


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
    with urllib.request.urlopen(req, timeout=30) as resp:
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
    with urllib.request.urlopen(req, timeout=30) as resp:
        content_type = resp.headers.get("Content-Type", "")
        data = resp.read()
    ext = guess_ext(content_type)
    dest = dest_base.with_suffix(ext)
    dest.write_bytes(data)
    return dest.name


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (ROOT / "data").mkdir(parents=True, exist_ok=True)

    payload = fetch_profile_json(USERNAME)
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

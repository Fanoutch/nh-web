"""Split documents into semantically coherent chunks.

Strategy (hybrid structural):
1. If the document contains "Section N - Title" / "Recette N - Title"
   headers, split on those boundaries first. Each section is a candidate
   chunk that preserves the semantic unit.
2. Else fall back to paragraph splitting on blank lines.
3. Apply size constraints:
     - Segments larger than MAX_CHUNK_SIZE are re-split on paragraphs,
       then on sentence boundaries if still too big.
     - In paragraph mode, consecutive small segments are merged until
       they reach TARGET_CHUNK_SIZE (capped by MAX).
     - In section mode, sections are kept as-is even if small — they
       represent meaningful semantic units (a small section is still
       a section).
4. Preamble (text before the first section) is kept as a separate chunk
   only if substantial; otherwise it is folded into the first section.

A chunk is a dict with keys:
    - chunk_id:      str         unique stable id (e.g. "manuel.pdf_p3_c0")
    - source:        str         path to the source file
    - page:          int | None  page number when known
    - position:      str         "début", "milieu" or "fin"
    - section_title: str | None  full title of "Section N - ..." if detected
    - text:          str         raw chunk text
    - hash:          str         sha256[:16] of text (for incremental updates)
"""
import hashlib
import re


# Default size constraints (in characters).
TARGET_CHUNK_SIZE = 800
MIN_CHUNK_SIZE = 200
MAX_CHUNK_SIZE = 1500


# Captures the full title after "Section N -" / "Recette N -".
KEYWORD_HEADER_PATTERN = re.compile(
    r"\b(?:Section|Recette)\s+\d+\s*[—\-:]?\s*([^\n]+)",
    re.IGNORECASE,
)

# Captures hierarchical numeric headers like "1.", "2.1.", "1.1.4." at the
# start of a line, followed by a substantial uppercase-starting title.
# Examples: "1. PRESENTATION", "2.1. Utilisation des marges", "1.2.3. Sub-section".
# Group 1 = numbering, group 2 = title text.
NUMERIC_HEADER_PATTERN = re.compile(
    r"(?:^|\n)\s*(\d+(?:\.\d+)*\.?)\s+([A-ZÀ-Ÿ][^\n]{2,})",
)

# Paragraph boundary: blank line (one or more newlines around whitespace).
PARAGRAPH_BOUNDARY = re.compile(r"\n\s*\n")

# Sentence boundary: punctuation + space + uppercase (incl. accented).
SENTENCE_BOUNDARY = re.compile(r"(?<=[.!?])\s+(?=[A-ZÀ-Ÿ])")


def _hash_text(text: str) -> str:
    """SHA-256 truncated to 16 hex chars — short content fingerprint."""
    return hashlib.sha256(text.encode("utf-8")).hexdigest()[:16]


def _parse_section_headers(text: str) -> list[tuple[int, str]]:
    """Find all section headers. Returns [(offset, title), ...] sorted by offset.

    Recognises two header styles:
        1. "Section N - Title" / "Recette N - Title"  (test corpus style)
        2. "1. TITLE", "2.1. Title", "1.2.3. Title"   (hierarchical numbering
           used in administrative / normative documents)
    """
    headers: list[tuple[int, str]] = []

    # Style 1: keyword + number + title
    for m in KEYWORD_HEADER_PATTERN.finditer(text):
        headers.append((m.start(), m.group(1).strip()))

    # Style 2: hierarchical numbering at line start. We keep both the number
    # and the title in the label so the citation reads e.g. "2.1. Utilisation".
    # m.start(1) is the offset of the first digit (not the leading newline).
    for m in NUMERIC_HEADER_PATTERN.finditer(text):
        numbering = m.group(1).strip()
        title = m.group(2).strip()
        full_label = f"{numbering} {title}"
        headers.append((m.start(1), full_label))

    headers.sort(key=lambda h: h[0])
    return headers


def _section_title_at_offset(
    headers: list[tuple[int, str]],
    offset: int,
) -> str | None:
    """Return the section title containing `offset`, or None if before any."""
    current: str | None = None
    for header_offset, title in headers:
        if header_offset <= offset:
            current = title
        else:
            break
    return current


def _position_at_offset(offset: int, total_length: int) -> str:
    """Return 'début' / 'milieu' / 'fin' based on relative offset."""
    if total_length <= 0:
        return "début"
    ratio = offset / total_length
    if ratio < 1 / 3:
        return "début"
    if ratio < 2 / 3:
        return "milieu"
    return "fin"


def _split_by_sections(
    text: str,
    headers: list[tuple[int, str]],
    min_size: int,
) -> list[tuple[int, int, str | None]]:
    """Build segments where each section is one segment.

    Returns a list of (start, end, section_title) tuples.

    Preamble (text before the first header) becomes its own segment with
    section_title=None if it has substantial content (>= min_size).
    Otherwise it is folded into the first section's segment, which carries
    that section's title.
    """
    segments: list[tuple[int, int, str | None]] = []
    first_header_offset = headers[0][0]

    # Decide where the first section starts: preamble is folded if too small.
    if first_header_offset >= min_size:
        segments.append((0, first_header_offset, None))
        first_section_start = first_header_offset
    else:
        first_section_start = 0

    # Each section spans from its header (or preamble start) to the next.
    for i, (header_offset, header_title) in enumerate(headers):
        section_end = headers[i + 1][0] if i + 1 < len(headers) else len(text)
        actual_start = first_section_start if i == 0 else header_offset
        segments.append((actual_start, section_end, header_title))

    return segments


def _split_by_paragraphs(text: str) -> list[tuple[int, int, str | None]]:
    """Split text on blank-line boundaries. Returns (start, end, None) tuples."""
    segments: list[tuple[int, int, str | None]] = []
    last_end = 0
    for match in PARAGRAPH_BOUNDARY.finditer(text):
        if match.start() > last_end:
            segments.append((last_end, match.start(), None))
        last_end = match.end()
    if last_end < len(text):
        segments.append((last_end, len(text), None))
    return segments if segments else [(0, len(text), None)]


def _split_by_sentences(
    text: str,
    seg_start: int,
    seg_end: int,
    target_size: int,
) -> list[tuple[int, int]]:
    """Split a long segment on sentence boundaries to ~target_size chunks.

    Returns plain (start, end) tuples — caller attaches the title.
    """
    sub = text[seg_start:seg_end]
    boundaries = [0]
    for m in SENTENCE_BOUNDARY.finditer(sub):
        boundaries.append(m.end())
    boundaries.append(len(sub))

    out: list[tuple[int, int]] = []
    chunk_start = 0
    for b in boundaries:
        if b - chunk_start >= target_size:
            out.append((seg_start + chunk_start, seg_start + b))
            chunk_start = b
    if chunk_start < len(sub):
        out.append((seg_start + chunk_start, seg_start + len(sub)))
    return out


def _split_oversized(
    text: str,
    seg_start: int,
    seg_end: int,
    target_size: int,
    max_size: int,
) -> list[tuple[int, int]]:
    """A segment exceeds max_size: re-split it on paragraphs then sentences.

    Returns plain (start, end) tuples — caller attaches the title.
    """
    sub = text[seg_start:seg_end]
    paragraph_tuples = _split_by_paragraphs(sub)
    # Translate paragraph offsets to global offsets.
    paragraphs: list[tuple[int, int]] = [
        (seg_start + s, seg_start + e) for s, e, _ in paragraph_tuples
    ]

    out: list[tuple[int, int]] = []
    for p_start, p_end in paragraphs:
        if p_end - p_start > max_size:
            # Still too big after paragraph split → use sentence boundaries.
            out.extend(_split_by_sentences(text, p_start, p_end, target_size))
        else:
            out.append((p_start, p_end))
    return out


def _merge_small_paragraphs(
    segments: list[tuple[int, int, str | None]],
    target_size: int,
    max_size: int,
) -> list[tuple[int, int, str | None]]:
    """Merge consecutive paragraph segments until reaching target_size.

    Used only in paragraph mode: grouping small paragraphs avoids producing
    many tiny chunks. Never produces a chunk larger than max_size.
    """
    if not segments:
        return []

    merged: list[tuple[int, int, str | None]] = []
    cur_start, cur_end, cur_title = segments[0]
    for next_start, next_end, next_title in segments[1:]:
        cur_size = cur_end - cur_start
        next_size = next_end - next_start
        # Only extend if the result stays within max_size and current is
        # still smaller than target.
        if cur_size < target_size and cur_size + next_size <= max_size:
            cur_end = next_end
        else:
            merged.append((cur_start, cur_end, cur_title))
            cur_start, cur_end, cur_title = next_start, next_end, next_title
    merged.append((cur_start, cur_end, cur_title))
    return merged


def chunk_documents(
    docs: list[dict],
    target_size: int = TARGET_CHUNK_SIZE,
    min_size: int = MIN_CHUNK_SIZE,
    max_size: int = MAX_CHUNK_SIZE,
) -> list[dict]:
    """Split each document into semantically coherent chunks.

    Args:
        docs:        documents from the loader (each with 'source', 'page', 'text').
        target_size: target chunk size in characters (default 800).
        min_size:    below this, segments are merged (paragraph mode only).
        max_size:    above this, segments are re-split.
    """
    if not (min_size < target_size < max_size):
        raise ValueError(
            f"Expected min_size ({min_size}) < target_size ({target_size}) < "
            f"max_size ({max_size})"
        )
    chunks: list[dict] = []
    for doc in docs:
        chunks.extend(_chunk_one(doc, target_size, min_size, max_size))
    return chunks


def _chunk_one(
    doc: dict,
    target_size: int,
    min_size: int,
    max_size: int,
) -> list[dict]:
    text = doc["text"]
    if not text or not text.strip():
        return []

    headers = _parse_section_headers(text)
    total_length = len(text)

    # 1. Initial segmentation. Both helpers return (start, end, title) tuples
    #    so we keep the section title attached to the segment through the
    #    refinement steps (no need to re-lookup at chunk-build time).
    if headers:
        segments = _split_by_sections(text, headers, min_size)
    else:
        segments = _split_by_paragraphs(text)

    # 2. Re-split oversized segments. The new sub-segments inherit the
    #    parent's title so they all map back to the same section.
    refined: list[tuple[int, int, str | None]] = []
    for s, e, title in segments:
        if e - s > max_size:
            for ss, se in _split_oversized(text, s, e, target_size, max_size):
                refined.append((ss, se, title))
        else:
            refined.append((s, e, title))

    # 3. In paragraph mode, merge consecutive small segments.
    if not headers:
        refined = _merge_small_paragraphs(refined, target_size, max_size)

    # 4. Build chunk dicts.
    out: list[dict] = []
    chunk_idx = 0
    for s, e, title in refined:
        piece = text[s:e].strip()
        if not piece:
            continue
        out.append({
            "chunk_id": f"{doc['source']}_p{doc['page']}_c{chunk_idx}",
            "source": doc["source"],
            "page": doc["page"],
            "position": _position_at_offset(s, total_length),
            "section_title": title,
            "text": piece,
            "hash": _hash_text(piece),
        })
        chunk_idx += 1
    return out

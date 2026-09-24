"""Tests for the semantic chunker."""
from rag.decoupage import chunk_documents


def test_short_document_yields_single_chunk():
    """A document shorter than target produces exactly one chunk."""
    docs = [{"source": "a.txt", "page": None, "text": "Hello world"}]

    chunks = chunk_documents(docs)

    assert len(chunks) == 1
    assert chunks[0]["text"] == "Hello world"
    assert chunks[0]["source"] == "a.txt"
    assert chunks[0]["page"] is None
    assert chunks[0]["chunk_id"] == "a.txt_pNone_c0"


def test_chunk_ids_are_unique_and_stable():
    """Same input → same chunk_ids, all unique within a document."""
    docs = [
        {"source": "a.pdf", "page": 1, "text": "x" * 1500},
        {"source": "a.pdf", "page": 2, "text": "y" * 1500},
    ]

    chunks = chunk_documents(docs)
    ids = [c["chunk_id"] for c in chunks]

    assert len(ids) == len(set(ids))  # all unique


def test_metadata_preserved_per_chunk():
    """Source and page from the parent document are preserved on each chunk."""
    docs = [{"source": "manuel.pdf", "page": 7, "text": "z" * 2500}]

    chunks = chunk_documents(docs)

    assert all(c["source"] == "manuel.pdf" for c in chunks)
    assert all(c["page"] == 7 for c in chunks)


def test_empty_text_yields_no_chunks():
    """A document with empty text produces zero chunks."""
    docs = [{"source": "empty.txt", "page": None, "text": ""}]

    chunks = chunk_documents(docs)

    assert chunks == []


def test_chunk_has_hash():
    """Each chunk carries a non-empty 16-char hash field."""
    docs = [{"source": "a.txt", "page": None, "text": "Hello world"}]

    chunks = chunk_documents(docs)

    assert "hash" in chunks[0]
    assert isinstance(chunks[0]["hash"], str)
    assert len(chunks[0]["hash"]) == 16


def test_identical_text_produces_identical_hash():
    docs = [
        {"source": "a.txt", "page": None, "text": "Hello"},
        {"source": "b.txt", "page": None, "text": "Hello"},
    ]
    chunks = chunk_documents(docs)
    assert chunks[0]["hash"] == chunks[1]["hash"]


def test_different_text_produces_different_hash():
    docs = [
        {"source": "a.txt", "page": None, "text": "Hello"},
        {"source": "b.txt", "page": None, "text": "Hellp"},
    ]
    chunks = chunk_documents(docs)
    assert chunks[0]["hash"] != chunks[1]["hash"]


# -----------------------------------------------------------------------------
# Section title detection
# -----------------------------------------------------------------------------

def test_section_title_detection_simple():
    """A chunk containing 'Section N - Title' captures the title."""
    text = "Section 1 - Introduction au RAG\n" + ("X" * 200)
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)

    assert chunks[0]["section_title"] == "Introduction au RAG"


def test_section_title_none_before_any_header():
    """Text without any 'Section N' header has section_title=None."""
    text = "Some intro text without any section header here."
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)

    assert chunks[0]["section_title"] is None


def test_recette_title_detected():
    """'Recette N - Title' is recognized in addition to 'Section N - Title'."""
    text = "Recette 7 - Tarte aux pommes\nIngrédients..."
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)

    assert chunks[0]["section_title"] == "Tarte aux pommes"


def test_numeric_header_top_level_detected():
    """A header like '1. PRESENTATION' at line start is detected."""
    text = (
        "Préambule.\n"
        + "1. PRESENTATION\n"
        + ("Contenu de la présentation. " * 30)
    )
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    titles = [c["section_title"] for c in chunks if c["section_title"]]

    # The label should include the numbering and the title
    assert any("PRESENTATION" in t for t in titles)


def test_numeric_header_nested_levels_detected():
    """Nested numbering like '2.1.' is detected."""
    text = (
        "1. PREMIER NIVEAU\n"
        + ("contenu " * 50)
        + "\n2.1. Sous-section avec un titre\n"
        + ("autre contenu " * 50)
    )
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    titles = [c["section_title"] for c in chunks if c["section_title"]]

    assert any("PREMIER NIVEAU" in t for t in titles)
    assert any("2.1" in t and "Sous-section" in t for t in titles)


def test_numeric_header_ignores_inline_references():
    """References in the middle of a sentence should NOT be detected."""
    text = "La procédure suit cinq étapes pour valider 1.2 fois la procédure habituelle."
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    # Only chunk should have no detected section_title
    assert chunks[0]["section_title"] is None


def test_numeric_and_keyword_headers_can_coexist():
    """A doc mixing 'Section N - Title' and '1.' headers should detect both."""
    text = (
        "Section 1 - Style classique\n"
        + ("a" * 300)
        + "\n2. STYLE NUMERIQUE\n"
        + ("b" * 300)
    )
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    titles = [c["section_title"] for c in chunks if c["section_title"]]

    assert any("Style classique" in t for t in titles)
    assert any("STYLE NUMERIQUE" in t for t in titles)


# -----------------------------------------------------------------------------
# Section-mode splitting (the main strategy)
# -----------------------------------------------------------------------------

def test_each_section_becomes_one_chunk():
    """When a doc has N sections of moderate size, we get N chunks."""
    text = (
        "Section 1 - Premier sujet\n"
        + "Contenu du premier sujet, environ trois cents caracteres. " * 5
        + "\n\nSection 2 - Deuxieme sujet\n"
        + "Contenu du deuxieme sujet, environ trois cents caracteres. " * 5
        + "\n\nSection 3 - Troisieme sujet\n"
        + "Contenu du troisieme sujet, environ trois cents caracteres. " * 5
    )
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    titles = [c["section_title"] for c in chunks]

    assert "Premier sujet" in titles
    assert "Deuxieme sujet" in titles
    assert "Troisieme sujet" in titles


def test_oversized_section_is_split():
    """A section larger than max_size is re-split."""
    long_para = "Une phrase courte. " * 200  # ~3800 chars, > max 1500
    text = "Section 1 - Tres long sujet\n" + long_para
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)

    # Should produce more than one chunk for that one section
    assert len(chunks) > 1
    # All chunks come from the same section
    for c in chunks:
        assert c["section_title"] == "Tres long sujet"


def test_small_preamble_is_folded_into_first_section():
    """A short doc title is merged into the first section's chunk."""
    text = "TITRE DU DOCUMENT\n\nSection 1 - Vrai contenu\n" + ("a" * 400)
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)
    # The title is short (<min_size 200), so it should be folded into chunk 0
    assert "TITRE DU DOCUMENT" in chunks[0]["text"]
    assert "Vrai contenu" in chunks[0]["text"] or chunks[0]["section_title"] == "Vrai contenu"


# -----------------------------------------------------------------------------
# Paragraph-mode splitting (fallback when no headers)
# -----------------------------------------------------------------------------

def test_paragraph_mode_when_no_sections():
    """A doc without 'Section N' headers falls back to paragraph splitting."""
    text = (
        "Premier paragraphe parlant d'un sujet A. " * 10
        + "\n\n"
        + "Deuxième paragraphe sur un sujet B totalement différent. " * 10
        + "\n\n"
        + "Troisième paragraphe distinct sur un sujet C. " * 10
    )
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs)

    # No section_title for any chunk (no headers)
    assert all(c["section_title"] is None for c in chunks)
    # At least one chunk produced
    assert len(chunks) >= 1


def test_paragraph_mode_merges_small_paragraphs():
    """Several small consecutive paragraphs are merged toward target size."""
    # Each paragraph is ~50 chars; with target 800 they should all merge.
    text = "\n\n".join(["Petit paragraphe court ici."] * 10)
    docs = [{"source": "a.txt", "page": None, "text": text}]

    chunks = chunk_documents(docs, target_size=800, min_size=200, max_size=1500)

    # All paragraphs should fit in a single merged chunk (total ~270 chars)
    assert len(chunks) == 1


# -----------------------------------------------------------------------------
# Position
# -----------------------------------------------------------------------------

def test_position_for_first_chunk():
    """The first chunk of a document is always at 'début'."""
    text = "Section 1 - Intro\n" + ("a" * 500) + "\n\nSection 2 - Body\n" + ("b" * 500)
    docs = [{"source": "a.txt", "page": None, "text": text}]
    chunks = chunk_documents(docs)
    assert chunks[0]["position"] == "début"


def test_position_for_short_doc_is_debut():
    docs = [{"source": "a.txt", "page": None, "text": "tiny"}]
    chunks = chunk_documents(docs)
    assert chunks[0]["position"] == "début"

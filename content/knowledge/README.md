# Knowledge Centre pillar copy

Optional authored intro copy for the eleven `/knowledge/{hub}` pillar pages.

## Convention

- One file per hub, named `{hub-slug}.md`, where the slug is a case value of
  `App\Enums\KnowledgeHub` — `fundamentals`, `cameroon-101`, `products`,
  `processing`, `grading`, `buying`, `export`, `compliance`, `sustainability`,
  `logistics`, `business`.
- **Plain markdown, no frontmatter.** The file is the body and nothing else —
  the page title, description and breadcrumb all come from the enum, not from
  the file.
- Start at `##`. The hub's `<h1>` is already rendered from `KnowledgeHub::label()`,
  so a top-level `#` in the file would produce a second one.
- The file is rendered through `App\Support\ArticleBody::render()`, so it gets
  the same treatment as an article body:
  - internal link schemes resolve to real routes —
    `[Sapele](species:sapele)`, `[sawn timber](marketplace:sawn_timber)`,
    `[suppliers](suppliers:)`, `[post an RFQ](rfq:)`, `[read more](insights:some-slug)`;
  - raw HTML is escaped, never passed through;
  - `##`/`###` headings get stable anchor ids.

## No file means no invented prose

A hub with no `{hub-slug}.md` renders its enum description and its article
listing only. That is deliberate: the pillar page is honest about what has
actually been written rather than showing placeholder copy. Add the file when
there is real copy to add — do not create an empty or stub file.

# GQ Polylang for WPGraphQL

Exposes [Polylang](https://polylang.pro)'s languages and translations in
[WPGraphQL](https://www.wpgraphql.com), for headless multilingual WordPress
sites. It works with Polylang and Polylang Pro.

A headless frontend can:
- list the site's languages, with their homes and translated title and tagline;
- resolve each language's URLs with `nodeByUri`, including each language's
  front page at its home (`/en/`);
- link a page to its translations;
- filter any connection by language;
- read each language's menus.

```graphql
{
  languages { code locale name uri title description }
  nodeByUri(uri: "/en/about/") {
    ... on Page {
      title
      language { code locale }
      translations { uri language { code } }
    }
  }
  menuItems(where: { location: PRIMARY, language: EN }) {
    nodes { label uri }
  }
}
```

## Requirements

- WordPress 6.5+ and PHP 7.4+.
- WPGraphQL 2.x.
- Polylang or Polylang Pro 3.7+.

## Install

With Composer:

```sh
composer require getquick/gq-polylang-graphql
```

Or download a release zip from GitHub, then activate it like any plugin.

It replaces [WP GraphQL Polylang](https://github.com/valu-digital/wp-graphql-polylang),
which inspired it, and refuses to run beside it. See
[Coming from WP GraphQL Polylang](#coming-from-wp-graphql-polylang).

## How it works

Polylang chooses a context for each request. Its frontend context detects a
language from the URL and filters every query by it, which would hide all but
one language from a request to `/graphql`. So during GraphQL requests this
plugin runs Polylang in its REST API context, through the `pll_context` filter.

In that context Polylang:
- filters links, so an English page's `uri` is `/en/about/`;
- sets up each language's static front page;
- filters no query until one asks for a language.

The schema then asks for a language explicitly.

## Schema

**Languages.** A `Language` has `id`, `code` (`LanguageCodeEnum`), `slug`,
`locale`, `name`, `isDefault`, `homeUrl`, `uri` (`/`, or `/en/`), `title`,
`description` and `frontPage`.
- `title` and `description` are the site title and tagline, translated
  through Polylang's string translations.
- `frontPage` is the language's translation of the static front page.

Root fields:
- `languages`: all of them, in Polylang's order.
- `defaultLanguage`.
- `language(code: EN)`.
- `translateString(string:, language:)`.

`LanguageCodeEnum` has one value per language, normally its Polylang slug in
upper case with hyphens replaced by underscores (`pt` → `PT`, `pt-br` →
`PT_BR`). `LanguageCodeFilterEnum` uses the same names and adds `DEFAULT` and
`ALL`, which always retain their filter sentinel meanings.

When multiple slugs normalize to the same name, **every** member receives
`NAME__HEX`, where `HEX` is the uppercase hexadecimal encoding of the complete
slug: `pt-br` → `PT_BR__70742D6272`, `pt_br` → `PT_BR__70745F6272`. Languages
named `all` or `default` use the same suffix rule even without a collision.
Ordinary nonconflicting names are reserved first; trailing underscores are
added if a generated name would collide with one of them. Allocation is
independent of language order and identical in both enums.

Existing nonconflicting names remain unchanged. Adding/removing a conflicting
language can change enum names for that collision group (or a generated name
with a new secondary conflict); clients must update affected query literals
and variables. Use schema introspection or `languages { slug code }` to discover
the current names. Polylang slugs and the enums' underlying values do not change.

**Content.** Every post type and taxonomy Polylang translates, and WPGraphQL
shows, gets these fields:
- `language`;
- `translations`: the other languages' versions the requester can see, so
  drafts and private posts are left out for the public;
- `translation(language: EN)`.

**Filters.** Connections to those types, and `contentNodes`, take
`where: { language: EN }` (or `DEFAULT`, or `ALL`) and
`where: { languages: [EN, PT] }`. Without either, a connection returns every
language.

**URIs.** `nodeByUri` resolves each language's URLs:
- A language's home (`/en/`) resolves to its translation of the static front
  page. That page has `isFrontPage: true` and `uri: "/en/"`, as the default
  language's has `/`.
- A URI resolves only to content in its own language: `/en/sobre/` is `null`,
  even though WordPress would find the Portuguese `sobre` page.
- Separate language domains/subdomains require a full URL, such as
  `https://en.example.com/about/`. Hostless paths are ambiguous and return
  `null`; the GraphQL request's host or current language is never guessed.
- Query-based language URLs retain `lang` and content selectors, such as
  `/?page_id=123&lang=en`. Relative URLs must include a valid `lang`;
  a full URL without `lang` identifies the default only when Polylang hides it.
- Directory URIs remain site-relative (`/en/about/`), including when WordPress
  is installed under `/blog`; `/blog/en/about/` and full URLs also work.
  Unknown hosts and wrong-language content return `null`. When every language
  has a visible directory prefix, an unprefixed URL is unresolved (`null`).

`uri` remains a path-only field: it cannot distinguish language homes on separate
hosts or query-based URLs. Use `homeUrl` for language homes, full language URLs
for domain-based content, or explicit `lang` for query-based content. A home URL
with content query parameters is not treated as the static front page.

`homeUrl` and `uri` are the language's home (`https://example.com/en/`) even
when Polylang's "front page URL contains the language code" option is off. A
headless frontend serves a language's front page at its home.

**Menus.** Polylang assigns a menu to each theme location per language.
- `menuItems(where: { location: PRIMARY, language: EN })` reads that language's
  menu at that location.
- With `language` alone, it reads every location's menu in that language.
- Without `language`, it reads the default language's menus, as WPGraphQL
  does.
- Nested `childItems` inherit their parent item's menu; no repeated language
  argument is needed, including for grandchildren. Explicit language/location
  arguments narrow that menu scope and cannot switch to another menu.
- The items of a menu assigned in any language are public. A menu at no
  location stays private (users authorized to edit menus retain access).

## Coming from WP GraphQL Polylang

The names match where the meaning does: `Language`, `LanguageCodeEnum`,
`LanguageCodeFilterEnum`, `languages`, `defaultLanguage`, `language`,
`translations`, `translation`, the `language`/`languages` where-arguments and
`translateString`. The differences:

- **Menus:** `menuItems(where: { location, language })` replaces the
  `PRIMARY___EN` location enum values.
- **Connections:** they return every language by default. WP GraphQL Polylang
  ran Polylang in its admin context to get the same result.
- **Front pages:** `language(code:) { frontPage }` and `nodeByUri("/en/")`
  return each language's front page.
- **No mutations:** there is no language input on mutations, and no ACF
  options pages yet.

## Development

`tests/integration/run.sh` runs the integration tests. It creates a disposable
[DDEV](https://ddev.com) WordPress in `.test-site` with WPGraphQL and Polylang
from WordPress.org, and builds a bilingual site (`tests/integration/fixture.php`).
Then `tests/integration/graphql.test.mjs` queries it over HTTP, as a headless
frontend would.

```sh
composer install
composer lint        # WordPress Coding Standards, PHP 7.4+
composer analyse     # PHPStan
tests/integration/run.sh
POLYLANG_DIR=../polylang-pro tests/integration/run.sh   # against Polylang Pro
```

URL regressions can be run without resetting `.test-site`:

```sh
tests/integration/run-language-urls.sh
```

This uses a separate temporary database and WordPress root to test domains,
subdomains, query URLs, directories, subdirectory installations, ambiguous
relative URLs, and wrong-language content against the installed plugins.

Hierarchical translated-menu scope and privacy regressions also use a separate
temporary database and WordPress root:

```sh
tests/integration/run-nested-menus.sh
```

Language enum collision, serialization, and input regressions use an isolated
database as well:

```sh
tests/integration/run-language-enums.sh
```

The empty-language schema regression can be run without resetting `.test-site`:

```sh
tests/integration/run-empty-languages.sh
```

It requires WordPress, WPGraphQL and Polylang already installed in `.test-site`.
It uses and cleans up a separate temporary database and WordPress root, checking
ordinary queries and authenticated introspection before language configuration,
with a language configured, and after the last language is removed.

## License

GPL-3.0-or-later. Made by [GETQUICK](https://getquick.io).

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

`LanguageCodeEnum` has one value per language: its Polylang slug in upper case
(`pt` → `PT`, `pt-br` → `PT_BR`). `LanguageCodeFilterEnum` adds `DEFAULT` and
`ALL`.

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

`homeUrl` and `uri` are the language's home (`https://example.com/en/`) even
when Polylang's "front page URL contains the language code" option is off. A
headless frontend serves a language's front page at its home.

**Menus.** Polylang assigns a menu to each theme location per language.
- `menuItems(where: { location: PRIMARY, language: EN })` reads that language's
  menu at that location.
- With `language` alone, it reads every location's menu in that language.
- Without `language`, it reads the default language's menus, as WPGraphQL
  does.
- The items of a menu assigned in any language are public. A menu at no
  location stays private.

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

## License

GPL-3.0-or-later. Made by [GETQUICK](https://getquick.io).

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

These are this plugin's minimums; newer dependency releases can require newer
WordPress/PHP. CI uses two explicitly compatible stacks, not every combination:

| Stack | WordPress | PHP | WPGraphQL | Polylang |
| --- | --- | --- | --- | --- |
| Minimum | 6.5 | 7.4 | 2.0.0 | 3.7 |
| Current (pinned) | 7.1.2 | 8.4 | 2.23.1 | 3.8.10 |

Polylang 3.7 declares WordPress 6.2+/PHP 7.2+; WPGraphQL 2.0.0 declares
WordPress 6.0+/PHP 7.4+, so they can exercise this plugin's advertised floor.
Setup verifies the installed versions, WordPress's PHP requirement, and both
plugin headers and readme dependency requirements before activation. Update the
current pins together after checking those constraints; do not pair the oldest
WordPress with arbitrary latest plugins. PHP 7.4 is EOL and included only for
compatibility testing, not as a deployment recommendation.

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
- Comment URLs (`/en/about/#comment-12`) resolve only when the comment's post
  is in the URL's language (or has none) and the requester may see the
  comment; otherwise, as for a missing comment, `null`.
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

`tests/integration/run.sh` runs the full integration suite. Use a fresh checkout
with its own [DDEV](https://ddev.com) project/database: it installs WordPress in
`.test-site`, resets **only that disposable database**, installs WPGraphQL and
Polylang from WordPress.org, and builds the bilingual fixture. It refuses an
existing unmarked `.test-site`; do not add its disposable marker to an existing
site to bypass this protection. Marked disposable runs are destructive on rerun.
It also refuses configurations targeting a database other than DDEV's `db`.

The HTTP suite queries it as an anonymous headless frontend. Then all existing
isolated suites run against the same installed versions: nested menus,
empty-language setup, URL configurations, language enums, and translation
batching/visibility. Each isolated suite creates and cleans up its own temporary
database, never resetting the base site's database.

```sh
composer install
composer lint        # WordPress Coding Standards, PHP 7.4+
composer analyse     # PHPStan
tests/integration/run.sh   # fresh disposable checkout; defaults to latest
WORDPRESS_VERSION=6.5 PHP_VERSION=7.4 WPGRAPHQL_VERSION=2.0.0 \
  POLYLANG_VERSION=3.7 tests/integration/run.sh
POLYLANG_DIR=../polylang-pro tests/integration/run.sh   # local Pro distribution
```

`WORDPRESS_VERSION`, `PHP_VERSION`, `WPGRAPHQL_VERSION`, and `POLYLANG_VERSION`
select the stack. `PHP_VERSION` reconfigures/restarts the disposable DDEV project;
the checked-in local default remains PHP 8.3. Core/plugin versions are honored on
marked reruns too. Automatic updates and WP-Cron are disabled in the disposable
base site; setup verifies core checksums and rechecks the stack after all suites
to detect version drift. The test theme is Twenty Twenty-One 2.6, compatible with both
stacks (Twenty Twenty-Five requires WordPress 6.7).

Both pinned stacks passed locally, including all 9 HTTP tests and the isolated
suites. Authenticated checks use fresh WP-CLI processes with administrator and
subscriber identities; they do not test HTTP cookie/application-password login.
URL checks cover directories, domains, subdomains, and query URLs under root and
subdirectory installs, including ambiguous/wrong-language URLs. Domain routing
is tested in-process, not with live DNS/TLS. Polylang Pro and GitHub-hosted CI
execution remain unverified; Pro is not distributed in the public CI matrix.

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

Translation batching, visibility, and query-count regressions use the installed
plugins with a separate temporary database (the existing database is untouched):

```sh
tests/integration/run-translation-batching.sh
```

This checks anonymous, administrator, and subscriber responses, including private
and draft translations, ordering, empty lists, repeated fields, and nested term
translations. It reports three cold-cache anonymous samples, authenticated
samples, and a final anonymous recheck. Pass `baseline` to report counts
without enforcing performance limits when comparing resolver implementations.
With WordPress 7.1.2, WPGraphQL 2.0.0, Polylang 3.8.10, and 21 parent posts,
deferring the resolver reduced translation batches from 20 to 1, post-loader SQL
queries from 68 to 11, and total anonymous connection queries from 73 to 16
(all three samples agreed; administrator totals were 71 to 14).

## License

GPL-3.0-or-later. Made by [GETQUICK](https://getquick.io).

# Changelog

## 0.1.2

- **Fix:** resolve language URLs consistently across directories, separate domains/subdomains, query URLs, and subdirectory installations; reject ambiguous hosts and wrong-language content.
- **Fix:** nested menu items inherit their parent's translated menu scope without exposing unassigned menus to unauthorized users.
- **Fix:** allocate deterministic GraphQL language enum names for colliding and reserved Polylang slugs.
- **Performance:** batch translation loading across sibling nodes while retaining visibility checks and omitting inaccessible translations. In the measured 21-post connection, translation batches fell from 20 to 1 and anonymous SQL queries from 73 to 16.
- **Tests:** exercise compatible minimum and current WordPress/PHP/WPGraphQL/Polylang stacks, including anonymous, administrator, and subscriber visibility, private translations, nested menus, empty-language states, language enums, and supported URL configurations.
- **Development:** parameterize dependency versions, verify dependency constraints and version drift, and restrict reset-based setup to marked disposable sites. Existing test databases are not reset by the isolated regression suites.

## 0.1.1

- **Fix:** ordinary GraphQL queries and schema introspection work when Polylang has no configured languages, including after the last language is removed. Language connection inputs are omitted until languages exist; configured-language behavior is unchanged.
- **Tests:** regression coverage for empty-language schema states uses an isolated temporary database without resetting the existing test site.

## 0.1.0

First release.

- **Languages:** the `Language` type, `LanguageCodeEnum` and `LanguageCodeFilterEnum`, plus the root fields `languages`, `defaultLanguage`, `language(code:)` and `translateString`. A language's `title` and `description` are translated.
- **Content:** `language`, `translations` and `translation(language:)` on translated post types and taxonomies. Connections take `where: { language, languages }`.
- **URIs:** `nodeByUri` resolves each language's front page at its home (`/en/`), and never to content in another language. A translated front page has `isFrontPage: true` and its home as `uri`.
- **Menus:** `menuItems(where: { location, language })` reads per-language menus, and the items of menus Polylang assigned are public.
- **Requests:** GraphQL requests run Polylang in its REST context, so nothing is filtered by language implicitly.

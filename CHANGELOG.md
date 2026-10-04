# Changelog

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

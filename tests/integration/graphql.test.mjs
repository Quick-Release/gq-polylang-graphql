// The plugin's schema over HTTP against the bilingual fixture (fixture.php),
// as an unauthenticated headless frontend sees it. run.sh sets GRAPHQL_URL.
import assert from "node:assert/strict";
import test from "node:test";

const GRAPHQL_URL = process.env.GRAPHQL_URL;
if (!GRAPHQL_URL) throw new Error("Set GRAPHQL_URL (tests/integration/run.sh does).");

async function graphql(query, variables = {}, { url = GRAPHQL_URL, method = "POST" } = {}) {
  const response =
    method === "GET"
      ? await fetch(`${url}${url.includes("?") ? "&" : "?"}query=${encodeURIComponent(query)}`)
      : await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ query, variables }),
        });
  const body = await response.json();
  assert.equal(body.errors, undefined, JSON.stringify(body.errors));
  return body.data;
}

const NODE = `
  __typename
  ... on Page { title uri isFrontPage language { code } }
  ... on Post { title uri language { code } }
`;

const byUri = async (uri) => (await graphql(`query ($uri: String!) { nodeByUri(uri: $uri) { ${NODE} } }`, { uri })).nodeByUri;

const titles = (nodes) => nodes.map(({ title }) => title).sort();

test("lists the languages, the default first, with their homes and translated identity", async () => {
  const { languages, defaultLanguage } = await graphql(`{
    languages { code slug locale name isDefault uri title description }
    defaultLanguage { code }
  }`);

  assert.deepEqual(languages, [
    { code: "PT", slug: "pt", locale: "pt_PT_ao90", name: "Português", isDefault: true, uri: "/", title: "Site de teste", description: "Uma descrição" },
    { code: "EN", slug: "en", locale: "en_US", name: "English", isDefault: false, uri: "/en/", title: "Test site", description: "A tagline" },
  ]);
  assert.deepEqual(defaultLanguage, { code: "PT" });
});

test("each language's front page is a front page at its home", async () => {
  assert.deepEqual(await byUri("/"), { __typename: "Page", title: "Início", uri: "/", isFrontPage: true, language: { code: "PT" } });
  assert.deepEqual(await byUri("/en/"), { __typename: "Page", title: "Home", uri: "/en/", isFrontPage: true, language: { code: "EN" } });

  const { language } = await graphql(`{ language(code: EN) { frontPage { title uri } } }`);
  assert.deepEqual(language.frontPage, { title: "Home", uri: "/en/" });
});

test("pages resolve by their language's URI, and only by it", async () => {
  assert.deepEqual(await byUri("/sobre/"), { __typename: "Page", title: "Sobre", uri: "/sobre/", isFrontPage: false, language: { code: "PT" } });
  assert.deepEqual(await byUri("/en/about/"), { __typename: "Page", title: "About", uri: "/en/about/", isFrontPage: false, language: { code: "EN" } });
  assert.equal(await byUri("/en/sobre/"), null);
  assert.equal(await byUri("/about/"), null);
});

test("a page links to its visible translations", async () => {
  const { nodeByUri } = await graphql(`{
    nodeByUri(uri: "/sobre/") {
      ... on Page {
        translations { title uri language { code } }
        translation(language: EN) { title }
      }
    }
  }`);
  assert.deepEqual(nodeByUri.translations, [{ title: "About", uri: "/en/about/", language: { code: "EN" } }]);
  assert.deepEqual(nodeByUri.translation, { title: "About" });

  // The English translation of Contactos is a draft: invisible to the public.
  const contactos = await graphql(`{
    nodeByUri(uri: "/contactos/") { ... on Page { translations { title } translation(language: EN) { title } } }
  }`);
  assert.deepEqual(contactos.nodeByUri, { translations: [], translation: null });

  const only = await graphql(`{ nodeByUri(uri: "/en/english-only/") { ... on Page { translations { title } } } }`);
  assert.deepEqual(only.nodeByUri.translations, []);
});

test("connections return every language unless asked for one", async () => {
  const query = `query ($where: RootQueryToPageConnectionWhereArgs) { pages(first: 50, where: $where) { nodes { title } } }`;
  const pages = async (where) => titles((await graphql(query, { where })).pages.nodes);

  const portuguese = ["Contactos", "Início", "Sobre", "Só em português"];
  const english = ["About", "English only", "Home"];
  assert.deepEqual(await pages(undefined), [...portuguese, ...english].sort());
  assert.deepEqual(await pages({ language: "ALL" }), [...portuguese, ...english].sort());
  assert.deepEqual(await pages({ language: "EN" }), english);
  assert.deepEqual(await pages({ language: "DEFAULT" }), portuguese);
  assert.deepEqual(await pages({ languages: ["PT"] }), portuguese);

  const { contentNodes } = await graphql(`{ contentNodes(first: 50, where: { contentTypes: [POST], language: EN }) { nodes { ... on Post { title uri } } } }`);
  assert.deepEqual(contentNodes.nodes, [{ title: "Article", uri: "/en/article/" }]);
});

test("terms carry their language and translations, and filter by language", async () => {
  // Polylang also gives each language a default category (Uncategorized).
  const { categories } = await graphql(`{
    categories(where: { language: EN }) { nodes { name language { code } translations { name } } }
  }`);
  assert.ok(categories.nodes.every(({ language }) => language.code === "EN"));
  assert.deepEqual(
    categories.nodes.find(({ name }) => name === "News"),
    { name: "News", language: { code: "EN" }, translations: [{ name: "Notícias" }] },
  );
  assert.equal(categories.nodes.find(({ name }) => name === "Notícias"), undefined);
});

test("menus are read per language at a location", async () => {
  const items = async (where) =>
    (await graphql(`query ($where: RootQueryToMenuItemConnectionWhereArgs) { menuItems(where: $where) { nodes { label uri } } }`, { where })).menuItems.nodes;

  assert.deepEqual(await items({ location: "PRIMARY" }), [{ label: "Sobre", uri: "/sobre/" }]);
  assert.deepEqual(await items({ location: "PRIMARY", language: "EN" }), [{ label: "About", uri: "/en/about/" }]);
  assert.deepEqual(await items({ location: "PRIMARY", language: "PT" }), [{ label: "Sobre", uri: "/sobre/" }]);

  // Without arguments, the default language's assigned menus; a menu at no
  // location stays private.
  assert.deepEqual((await items(undefined)).map(({ label }) => label), ["Sobre"]);
});

test("translateString translates a registered string", async () => {
  const data = await graphql(`{ en: translateString(string: "Uma descrição", language: EN) pt: translateString(string: "Uma descrição", language: PT) }`);
  assert.deepEqual(data, { en: "A tagline", pt: "Uma descrição" });
});

test("a GET request to ?graphql gets the same languages", async () => {
  const url = GRAPHQL_URL.replace(/\/graphql$/u, "/?graphql");
  const data = await graphql(`{ pages(first: 50, where: { language: EN }) { nodes { title } } }`, {}, { url, method: "GET" });
  assert.deepEqual(titles(data.pages.nodes), ["About", "English only", "Home"]);
});

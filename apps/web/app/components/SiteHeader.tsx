import { content } from "../content";

export function SiteHeader() {
  return (
    <header className="masthead">
      <a className="brand" href="/">{content.common.siteTitle}</a>
      <nav aria-label={content.navigation.mainNavigationLabel}>
        <a href="/miejsca">{content.navigation.placesCatalog}</a>
      </nav>
    </header>
  );
}

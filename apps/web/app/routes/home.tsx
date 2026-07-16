export function meta() {
  return [
    { title: "FamilyPlaces | Miejsca przyjazne rodzinom" },
    {
      name: "description",
      content: "Znajduj miejsca dopasowane do wieku dziecka i potrzeb rodziny.",
    },
  ];
}

export default function Home() {
  return (
    <main className="shell">
      <header className="masthead">
        <a className="brand" href="/">FamilyPlaces</a>
        <span className="status">Katalog demonstracyjny</span>
      </header>
      <section className="hero">
        <p className="eyebrow">Rodzinne odkrywanie miasta</p>
        <h1>Miejsca dobrane do wieku, nie do przypadku.</h1>
        <p className="lede">
          Serwerowo renderowany katalog FamilyPlaces jest gotowy. W kolejnym
          checkpoincie pojawią się filtrowanie geograficzne i mapa z tekstową alternatywą.
        </p>
      </section>
    </main>
  );
}

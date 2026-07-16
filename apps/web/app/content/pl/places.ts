export const places = {
  resultsEyebrow: "Znalezione miejsca",
  resultsHeadingPlural: (count: number) => {
    if (count === 1) {
      return "1 propozycja dla rodziny";
    }
    const lastDigit = count % 10;
    const lastTwoDigits = count % 100;
    if (lastDigit >= 2 && lastDigit <= 4 && (lastTwoDigits < 10 || lastTwoDigits >= 20)) {
      return `${count} propozycje dla rodziny`;
    }
    return `${count} propozycji dla rodziny`;
  },
  placeMetaSeparator: " · ",
  indoor: "wewnątrz",
  outdoor: "na zewnątrz",
  freeEntry: "bezpłatnie",
  noResults: "Brak miejsc dla tych filtrów. Zmień kryteria i spróbuj ponownie.",
  previousPage: "← Poprzednia",
  nextPage: "Następna →",
  paginationLabel: "Strony wyników",
  paginationPageInfo: (current: number, total: number) => `Strona ${current} z ${total}`,
  formSearch: "Szukaj",
  formCity: "Miasto",
  formCategory: "Kategoria",
  formAge: "Wiek w miesiącach",
  formLat: "Latitude",
  formLng: "Longitude",
  formRadius: "Promień km",
  formIndoor: "wewnątrz",
  formAmenitiesHeader: "Udogodnienia (wszystkie wybrane)",
  filterButton: "Filtruj",
  allCitiesOption: "Wszystkie",
  allCategoriesOption: "Wszystkie",
  verifiedPlace: "miejsce zweryfikowane",
  aboutPlace: "O miejscu",
  amenitiesHeading: "Udogodnienia",
  infoHeading: "Informacje",
  addressLabel: "Adres",
  spaceLabel: "Przestrzeń",
  entryLabel: "Wstęp",
  freeEntryLabel: "bezpłatny",
  paidEntryLabel: "sprawdź cennik na miejscu",
  spaceAnd: " i ",
};
